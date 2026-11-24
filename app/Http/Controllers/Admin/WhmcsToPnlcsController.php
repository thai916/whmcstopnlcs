<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhmcsToPnlcsArchive;
use App\Models\WhmcsToPnlcsConnection;
use App\Models\WhmcsToPnlcsProfile;
use App\Models\WhmcsToPnlcsReport;
use App\Models\WhmcsToPnlcsRun;
use App\Services\WhmcsToPnlcs\MigrationRunner;
use App\Services\WhmcsToPnlcs\ReconciliationService;
use App\Services\WhmcsToPnlcs\ReportService;
use App\Services\WhmcsToPnlcs\SafetyGuard;
use App\Services\WhmcsToPnlcs\SourceAnalyzer;
use App\Services\WhmcsToPnlcs\SourceCatalog;
use App\Services\WhmcsToPnlcs\WhmcsConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Throwable;

class WhmcsToPnlcsController extends Controller
{
    public function __construct(
        protected SourceCatalog $catalog,
        protected SourceAnalyzer $analyzer,
        protected MigrationRunner $runner,
        protected ReconciliationService $reconciliation,
        protected ReportService $reports,
    ) {}

    public function index()
    {
        $diagnostics = session('wtp_diagnostics');
        $connections = WhmcsToPnlcsConnection::orderBy('id')->get();

        $savedSourceCurrencies = [];
        foreach ($connections as $connection) {
            $savedSourceCurrencies[$connection->id] = $this->sourceCurrencyOptions($connection);
        }

        return view('admin.whmcs-to-pnlcs.index', [
            'connections' => $connections,
            'runs' => WhmcsToPnlcsRun::orderByDesc('id')->limit(20)->get(),
            'profiles' => WhmcsToPnlcsProfile::orderBy('name')->get(),
            'diagnostics' => $diagnostics,
            'checklist' => SafetyGuard::checklist(),
            'sourceCurrencies' => $diagnostics['source_currencies'] ?? [],
            'savedSourceCurrencies' => $savedSourceCurrencies,
            'pnlcsCurrencies' => $this->pnlcsCurrencyOptions(),
            'defaultPnlcsCurrencyId' => $this->defaultPnlcsCurrencyId(),
        ]);
    }

    /** Test without saving; never echo credentials. */
    public function testConnection(Request $request)
    {
        $config = $this->connectionRules($request);
        $prefix = $config['prefix'] ?: 'tbl';

        try {
            $connector = new WhmcsConnector($config + ['allow_insecure' => $request->boolean('allow_insecure')]);
            $connector->test();
            $analysis = $this->analyzer->analyze($connector, $this->catalog, $prefix);
            $sourceCurrencies = $this->analyzer->currencies($connector, $prefix);
        } catch (Throwable $e) {
            return back()->withInput($this->safeInput($request))->with('error', $e->getMessage());
        }

        return back()->withInput($this->safeInput($request))->with('wtp_diagnostics', [
            'server' => $analysis['server'],
            'version' => $analysis['version'],
            'table_count' => $analysis['table_count'],
            'counts' => $analysis['counts'],
            'grants' => $analysis['grant_coverage'],
            'timezone' => $analysis['timezone'],
            'tables' => $analysis['tables'],
            'source_currencies' => $sourceCurrencies,
        ])->with('success', __('whmcs_to_pnlcs.connection_ok'));
    }

    public function saveConnection(Request $request)
    {
        $validated = $this->connectionRules($request);
        $validated['name'] = $request->filled('name') ? $request->string('name')->toString() : null;
        $validated['tls_mode'] = $validated['tls_mode'] ?? 'verified';
        $prefix = $validated['prefix'] ?: 'tbl';
        $validated['prefix'] = $prefix;

        try {
            $connector = new WhmcsConnector($validated + ['allow_insecure' => $request->boolean('allow_insecure')]);
            $connector->test();
            $sourceCurrencies = $this->analyzer->currencies($connector, $prefix);
        } catch (Throwable $e) {
            return back()->withInput($this->safeInput($request))->withErrors(['connection' => $e->getMessage()]);
        }

        $sourceIds = array_column($sourceCurrencies, 'id');
        if (! empty($validated['whmcs_currency_id']) && ! in_array((int) $validated['whmcs_currency_id'], $sourceIds, true)) {
            return back()->withInput($this->safeInput($request))->withErrors(['whmcs_currency_id' => __('whmcs_to_pnlcs.connection.currency_invalid')]);
        }
        if (! empty($validated['pnlcs_currency_id'])
            && ! DB::table('currencies')->where('id', (int) $validated['pnlcs_currency_id'])->exists()) {
            return back()->withInput($this->safeInput($request))->withErrors(['pnlcs_currency_id' => __('whmcs_to_pnlcs.connection.currency_invalid')]);
        }

        if (empty($validated['whmcs_currency_id']) && $sourceCurrencies !== []) {
            foreach ($sourceCurrencies as $currency) {
                if ($currency['is_default']) {
                    $validated['whmcs_currency_id'] = $currency['id'];
                    break;
                }
            }
            if (empty($validated['whmcs_currency_id'])) {
                $validated['whmcs_currency_id'] = $sourceCurrencies[0]['id'];
            }
        }

        if (empty($validated['pnlcs_currency_id'])) {
            $validated['pnlcs_currency_id'] = $this->defaultPnlcsCurrencyId();
        }
        $validated['port'] = $validated['port'] ?: 3306;
        $validated['last_tls_active'] = ! empty($connector->diagnostics()['tls_active']);
        $validated['last_identity_verified'] = $validated['tls_mode'] === 'verified';
        $validated['last_tested_at'] = now();

        $connection = WhmcsToPnlcsConnection::create($validated);

        return redirect()->route('admin.whmcs-to-pnlcs.analyze', $connection)
            ->with('success', __('whmcs_to_pnlcs.connection_saved'));
    }

    /** Save the explicit conversion pair for an existing connection. */
    public function saveCurrencies(Request $request, WhmcsToPnlcsConnection $connection)
    {
        $data = $request->validate([
            'whmcs_currency_id' => 'nullable|integer|min:1',
            'pnlcs_currency_id' => 'nullable|integer|min:1',
        ]);

        try {
            $connector = $this->sourceConnector($connection);
            $connector->test();
            $sourceCurrencies = $this->analyzer->currencies($connector, $connection->config()['prefix']);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $sourceIds = array_column($sourceCurrencies, 'id');
        if (! empty($data['whmcs_currency_id']) && ! in_array((int) $data['whmcs_currency_id'], $sourceIds, true)) {
            return back()->withErrors(['whmcs_currency_id' => __('whmcs_to_pnlcs.connection.currency_invalid')]);
        }
        if (! empty($data['pnlcs_currency_id'])
            && ! DB::table('currencies')->where('id', (int) $data['pnlcs_currency_id'])->exists()) {
            return back()->withErrors(['pnlcs_currency_id' => __('whmcs_to_pnlcs.connection.currency_invalid')]);
        }

        $connection->update([
            'whmcs_currency_id' => $data['whmcs_currency_id'] ?: null,
            'pnlcs_currency_id' => $data['pnlcs_currency_id'] ?: null,
        ]);

        return back()->with('success', __('whmcs_to_pnlcs.connection.currency_saved'));
    }

    /** Steps 2–5: source, destination, scope and field mapping. */
    public function analyze(WhmcsToPnlcsConnection $connection)
    {
        try {
            $config = $connection->config();
            $connector = new WhmcsConnector($config + ['allow_insecure' => $connection->tls_mode === 'none']);
            $connector->test();
            $analysis = $this->analyzer->analyze($connector, $this->catalog, $config['prefix']);
        } catch (Throwable $e) {
            return redirect()->route('admin.whmcs-to-pnlcs.index')->with('error', $e->getMessage());
        }

        return view('admin.whmcs-to-pnlcs.analyze', [
            'connection' => $connection,
            'analysis' => $analysis,
            'destination' => $this->analyzer->destination(),
            'groups' => $this->catalog->scopeGroups(),
            'catalog' => $this->catalog->all(),
            'safety' => $this->safety()->check(),
        ]);
    }

    /** Step 6 (dry run) or step 7 (real import): create a resumable run. */
    public function prepare(Request $request, WhmcsToPnlcsConnection $connection)
    {
        $data = $request->validate([
            'scope' => 'required|array|min:1',
            'scope.*' => 'string|max:64',
            'batch_size' => 'nullable|integer|min:1|max:1000',
            'duplicate_strategy' => 'nullable|in:skip,update,flag',
            'timezone_override' => 'nullable|string|max:64',
            'archive_extras' => 'nullable|boolean',
            'dry_run' => 'nullable|boolean',
            'name' => 'nullable|string|max:255',
        ]);

        $prepared = $this->runner->prepare($connection, $data);

        return redirect()->route('admin.whmcs-to-pnlcs.run', $prepared['run'])
            ->with('success', $data['dry_run'] ?? false
                ? __('whmcs_to_pnlcs.dry_run_created')
                : __('whmcs_to_pnlcs.run_created'));
    }

    /** Step 7/8: progress, verification and final report. */
    public function show(WhmcsToPnlcsRun $run)
    {
        return view('admin.whmcs-to-pnlcs.run', [
            'run' => $run,
            'progress' => $this->runner->progress($run),
            'reconciliation' => $run->status === 'completed' ? $this->reconciliation->run($run) : null,
            'checklist' => SafetyGuard::checklist(),
            'reports' => WhmcsToPnlcsReport::where('run_id', $run->id)->orderByDesc('id')->get(),
        ]);
    }

    public function batch(WhmcsToPnlcsRun $run): JsonResponse
    {
        return response()->json($this->runner->batch($run));
    }

    public function pause(WhmcsToPnlcsRun $run)
    {
        $this->runner->pause($run);

        return back()->with('success', __('whmcs_to_pnlcs.paused'));
    }

    public function resume(WhmcsToPnlcsRun $run)
    {
        $this->runner->resume($run);

        return back()->with('success', __('whmcs_to_pnlcs.resumed'));
    }

    public function cancel(WhmcsToPnlcsRun $run)
    {
        $this->runner->cancel($run);

        return back()->with('success', __('whmcs_to_pnlcs.cancelled'));
    }

    public function retry(WhmcsToPnlcsRun $run)
    {
        $count = $this->runner->retryFailed($run);

        return back()->with('success', __('whmcs_to_pnlcs.retries_queued', ['count' => $count]));
    }

    public function reconcile(WhmcsToPnlcsRun $run)
    {
        return view('admin.whmcs-to-pnlcs.reconcile', [
            'run' => $run,
            'report' => $this->reconciliation->run($run),
        ]);
    }

    public function buildReport(WhmcsToPnlcsRun $run, Request $request)
    {
        $type = $request->validate(['type' => 'required|string|max:32'])['type'];
        $format = $request->input('format', 'csv');
        $report = $this->reports->build($run, $type, $format);

        return redirect()->route('admin.whmcs-to-pnlcs.run', $run)
            ->with('success', __('whmcs_to_pnlcs.report_ready'));
    }

    public function downloadReport(WhmcsToPnlcsReport $report): Response
    {
        $content = $this->reports->output($report);
        $mime = $report->format === 'json' ? 'application/json' : 'text/csv';

        return response($content, 200)
            ->header('Content-Type', $mime.'; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$report->filename.'"')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function records(WhmcsToPnlcsRun $run)
    {
        return view('admin.whmcs-to-pnlcs.records', [
            'run' => $run,
            'records' => $run->records()->orderBy('entity')->orderBy('source_id')->paginate(100),
        ]);
    }

    /** Read-only view of archived WHMCS history. */
    public function archive()
    {
        return view('admin.whmcs-to-pnlcs.archive', [
            'records' => WhmcsToPnlcsArchive::orderByDesc('id')->paginate(100),
        ]);
    }

    public function saveProfile(Request $request, WhmcsToPnlcsConnection $connection)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'scope' => 'nullable|array',
            'options' => 'nullable|array',
        ]);

        WhmcsToPnlcsProfile::create([
            'connection_id' => $connection->id,
            'name' => $data['name'],
            'scope' => $data['scope'] ?? [],
            'options' => $data['options'] ?? [],
            'field_mapping' => [],
        ]);

        return back()->with('success', __('whmcs_to_pnlcs.profile_saved'));
    }

    public function deleteProfile(WhmcsToPnlcsProfile $profile)
    {
        $profile->delete();

        return back()->with('success', __('whmcs_to_pnlcs.profile_deleted'));
    }

    protected function safety(): SafetyGuard
    {
        return app(SafetyGuard::class);
    }

    protected function sourceConnector(WhmcsToPnlcsConnection $connection): WhmcsConnector
    {
        $config = $connection->config();
        if ($config['tls_mode'] === 'none') {
            // The administrator confirmed this when the connection was saved.
            $config['allow_insecure'] = true;
        }

        return new WhmcsConnector($config);
    }

    /** @return list<array{id: int, code: string, rate: float, is_default: bool}> */
    protected function sourceCurrencyOptions(WhmcsToPnlcsConnection $connection): array
    {
        try {
            $connector = $this->sourceConnector($connection);
            $connector->test();

            return $this->analyzer->currencies($connector, $connection->config()['prefix']);
        } catch (Throwable) {
            // A first-page render must survive an unreachable saved source.
            return [];
        }
    }

    /** @return list<array{id: int, code: string, rate: float, is_default: bool}> */
    protected function pnlcsCurrencyOptions(): array
    {
        return DB::table('currencies')
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->get(['id', 'code', 'rate'])
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'rate' => (float) $row->rate,
                'is_default' => false,
            ])
            ->all();
    }

    protected function defaultPnlcsCurrencyId(): ?int
    {
        $id = DB::table('currencies')->where('is_default', 1)->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Old input is flashed into the session; never let a source password
     * (or any other credential) reach it, even transiently.
     *
     * @return array<string, mixed>
     */
    protected function safeInput(Request $request): array
    {
        return $request->except(['password', 'password_confirmation']);
    }

    /** @return array<string, mixed> */
    protected function connectionRules(Request $request): array
    {
        $rules = [
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/'],
            'port' => 'required|integer|min:1|max:65535',
            'database' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_$-]+$/'],
            'username' => 'required|string|max:255',
            'password' => 'nullable|string',
            'prefix' => 'nullable|string|max:64',
            'whmcs_currency_id' => 'nullable|integer|min:1',
            'pnlcs_currency_id' => 'nullable|integer|min:1',
            'tls_mode' => 'required|in:verified,unverified,none',
            'timeout' => 'nullable|integer|min:1|max:120',
            'ca_path' => 'nullable|string|max:512',
            'tls_hostname' => 'nullable|string|max:255',
            'allow_insecure' => 'nullable|boolean',
        ];

        $validated = $request->validate($rules);

        if (($validated['tls_mode'] ?? '') === 'none' && ! $request->boolean('allow_insecure')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'allow_insecure' => __('whmcs_to_pnlcs.insecure_confirmation_required'),
            ]);
        }

        return $validated;
    }
}
