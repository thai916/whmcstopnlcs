<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\WhmcsImportConnection;
use App\Services\WhmcsImport\HistoryAttachments;
use App\Services\WhmcsImport\HistoryCatalog;
use App\Services\WhmcsImport\HistoryFinance;
use App\Services\WhmcsImport\HistoryImporter;
use App\Services\WhmcsImport\HistoryStore;
use App\Services\WhmcsImport\WhmcsConnector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WhmcsHistoryController extends Controller
{
    public function __construct(private HistoryStore $store, private HistoryAttachments $attachments)
    {
    }

    public function index()
    {
        $this->authorizeHistory();

        return $this->console();
    }

    public function run(Request $request)
    {
        $this->authorizeHistory();
        $validated = $request->validate([
            'connection_id' => ['required', 'integer', 'exists:whmcs_import_connections,id'],
            'source_key' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]{1,64}$/D'],
            'snapshot_key' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]{1,64}$/D'],
            'categories' => ['required', 'array', 'min:1', 'max:'.count(HistoryCatalog::definitions())],
            'categories.*' => ['required', 'string', 'distinct', Rule::in(array_keys(HistoryCatalog::definitions()))],
            'mode' => ['required', Rule::in(['dry_run', 'archive'])],
            'resume' => ['nullable', 'integer', 'min:1'],
            'restored_backup' => ['accepted'],
            'select_only' => ['accepted'],
        ]);
        $connection = WhmcsImportConnection::findOrFail($validated['connection_id']);
        if (($connection->prefix ?: 'tbl') !== 'tbl') {
            return back()->withErrors(['history' => __('whmcs_history.prefix_unsupported')])->withInput();
        }
        try {
            if ($validated['mode'] === 'archive') {
                $this->store->assertIsolated();
                if (! $this->store->profileSchemaInstalled()) {
                    throw new \RuntimeException('Historical profile schema is not installed.');
                }
            }
            $report = (new HistoryImporter($this->store))->run(
                new WhmcsConnector($connection->config()),
                $validated['source_key'], $validated['snapshot_key'], $validated['categories'],
                $validated['mode'] === 'dry_run', isset($validated['resume']) ? (int) $validated['resume'] : null,
            );
        } catch (\Throwable $e) {
            // Connection exceptions may contain credentials or source data.
            // Do not flash, log, or render their messages.
            return back()->withErrors(['history' => __('whmcs_history.run_failed')])->withInput();
        }

        return $this->console($report);
    }

    public function client(Request $request, Client $client)
    {
        $this->authorizeHistory();
        $installed = $this->store->profileSchemaInstalled();
        $request->validate([
            'category' => ['nullable', Rule::in(array_keys(HistoryCatalog::definitions()))],
            'after' => ['nullable', 'integer', 'min:0'],
            'source' => ['nullable', 'string', 'regex:/^[A-Za-z0-9._-]{1,64}$/D'],
        ]);
        $records = [];
        $links = [];
        $next = null;
        if ($installed) {
            $query = $this->store->profileRecords($client->id)->where('id', '>', $request->integer('after'));
            if ($request->filled('category')) {
                $query->where('source_table', HistoryCatalog::definitions()[$request->input('category')]['table']);
            }
            if ($request->filled('source')) {
                $query->where('source_key', $request->input('source'));
            }
            $records = $query->orderBy('id')->limit(26)->get()->all();
            if (count($records) > 25) {
                array_pop($records);
                $next = end($records)->id;
            }
            $links = DB::table('whmcs_history_client_links')->where('client_id', $client->id)->orderBy('id')->get();
        }

        return response()->view('admin.whmcs-history.client', [
            'client' => $client, 'installed' => $installed, 'records' => $records, 'next' => $next,
            'links' => $links, 'categories' => HistoryCatalog::definitions(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function record(Client $client, int $record)
    {
        $this->authorizeHistory();
        abort_unless($this->store->profileSchemaInstalled(), 404);
        // No unscoped find followed by a presentation-only authorization flag.
        $record = $this->store->profileRecords($client->id)->where('id', $record)->first();
        abort_unless($record, 404);
        $json = $this->store->decode($record);
        abort_unless(hash_equals($record->checksum, hash('sha256', $json)), 409);
        $finance = $record->source_table === 'tblinvoices'
            ? (new HistoryFinance($this->store))->invoice($client->id, $record) : null;

        return response()->view('admin.whmcs-history.record', [
            'client' => $client, 'record' => $record, 'payload' => $json, 'finance' => $finance,
            'attachments' => $this->attachments->forRecord($record->id),
        ])->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function attachment(Client $client, int $record, int $attachment)
    {
        $this->authorizeHistory();
        abort_unless($this->store->profileSchemaInstalled()
            && $this->store->profileRecords($client->id)->where('id', $record)->exists(), 404);
        try {
            $file = $this->attachments->download($record, $attachment);
        } catch (\RuntimeException $e) {
            abort(404);
        }

        return response()->download($file['path'], $file['name'], [
            'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function link(Request $request, Client $client)
    {
        $this->authorizeHistory();
        $validated = $request->validate([
            'source_key' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]{1,64}$/D'],
            'client_source_id' => ['required', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'],
            'confirmed' => ['accepted'],
        ]);
        try {
            $this->store->linkClient($validated['source_key'], $validated['client_source_id'], $client->id, auth('admin')->id());
            $this->store->rebuildOwnership($validated['source_key']);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['history' => __('whmcs_history.link_failed')])->withInput();
        }

        return redirect()->route('admin.clients.whmcs-history', $client)->with('success', __('whmcs_history.linked'));
    }

    private function authorizeHistory(): void
    {
        // Archives include internal notes, mail and arbitrary sensitive history.
        // A normal view_clients/manage_settings permission is NOT sufficient.
        $admin = auth('admin')->user();
        abort_unless($admin && ! $admin->is_disabled && $admin->role?->is_full_admin, 403);
    }

    private function console(?array $report = null)
    {
        $installed = $this->store->profileSchemaInstalled();

        return response()->view('admin.whmcs-history.index', [
            'connections' => WhmcsImportConnection::orderBy('id')->get(),
            'categories' => HistoryCatalog::definitions(), 'installed' => $installed,
            'runs' => $installed ? DB::table('whmcs_history_runs')->orderByDesc('id')->limit(20)->get() : [],
            'report' => $report,
        ])->header('Cache-Control', 'private, no-store');
    }
}
