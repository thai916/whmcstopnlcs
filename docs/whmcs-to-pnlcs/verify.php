<?php

/**
 * End-to-end verification for the WHMCS to PNLCS utility.
 *
 * IMPORTANT: this script writes test records. It refuses to run against the
 * application database named in .env (or WTP_APP_DB) unless
 * WTP_ALLOW_APP_DB=1 is set. Run it against a separate database created with:
 *
 *   DB_DATABASE=pnlcs_wtptest_target php artisan migrate --force
 *
 * Then:
 *
 *   DB_DATABASE=pnlcs_wtptest_target php docs/whmcs-to-pnlcs/verify.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\WhmcsToPnlcsConnection;
use App\Models\WhmcsToPnlcsMapping;
use App\Models\WhmcsToPnlcsRecord;
use App\Services\WhmcsToPnlcs\MigrationRunner;
use App\Services\WhmcsToPnlcs\ReconciliationService;
use App\Services\WhmcsToPnlcs\ReportService;
use App\Services\WhmcsToPnlcs\SourceAnalyzer;
use App\Services\WhmcsToPnlcs\SourceCatalog;
use App\Services\WhmcsToPnlcs\WhmcsConnector;
use Illuminate\Support\Facades\DB;

$cfg = config('database.connections.mysql');
$currentDb = (string) DB::connection()->getDatabaseName();

// DB_DATABASE may be overridden on the command line to select the disposable
// target, so config('database...') cannot identify the real application DB.
// Read the baseline from .env (or an explicit WTP_APP_DB) instead.
$applicationDb = getenv('WTP_APP_DB');
if (! is_string($applicationDb) || $applicationDb === '') {
    $envFile = base_path('.env');
    if (! is_file($envFile)) {
        fwrite(STDERR, "Cannot locate .env to identify the application database; set WTP_APP_DB.\n");
        exit(1);
    }
    foreach (file($envFile) as $line) {
        if (preg_match('/^\s*DB_DATABASE\s*=\s*(.*?)\s*$/', $line, $match)) {
            $applicationDb = trim(preg_replace('/\s+#.*$/', '', $match[1]) ?? '');
            if (preg_match('/^(["\'])(.*)\1$/', $applicationDb, $quoted)) {
                $applicationDb = $quoted[2];
            }
            break;
        }
    }
}
$applicationDb = trim((string) $applicationDb);

if ($applicationDb === '') {
    fwrite(STDERR, "Cannot identify the application database; set WTP_APP_DB.\n");
    exit(1);
}
if ($currentDb === $applicationDb && getenv('WTP_ALLOW_APP_DB') !== '1') {
    fwrite(STDERR, "Refusing to write verification data to the application database ($currentDb).\n");
    exit(1);
}

$sourceDb = getenv('WTP_TEST_SOURCE_DB') ?: 'pnlcs_wtptest_source';

$pass = 0;
$fail = 0;

function ok(bool $condition, string $message): void
{
    global $pass, $fail;
    echo ($condition ? 'PASS  ' : 'FAIL  ').$message."\n";
    $condition ? $pass++ : $fail++;
}

function runToCompletion(MigrationRunner $runner, $run, int $limit = 300): array
{
    $runner->start($run);
    $progress = ['status' => $run->status];
    for ($i = 0; $i < $limit; $i++) {
        $progress = $runner->batch($run);
        if (in_array($progress['status'], ['completed', 'failed', 'cancelled'], true)) {
            break;
        }
    }

    return $progress;
}

echo "Target: $currentDb\nSource: $sourceDb\n\n";

$catalog = new SourceCatalog;
$analyzer = new SourceAnalyzer;
$runner = app(MigrationRunner::class);

$missingSourceRefs = [];
foreach ($catalog->all() as $entity => $definition) {
    foreach (array_keys($definition['references'] ?? []) as $field) {
        if (! array_key_exists($field, $definition['source_refs'] ?? [])) {
            $missingSourceRefs[] = "$entity.$field";
        }
    }
}
ok(
    $missingSourceRefs === [],
    'Every catalog reference declares its source column'.($missingSourceRefs ? ': '.implode(', ', $missingSourceRefs) : '')
);

$connection = WhmcsToPnlcsConnection::create([
    'name' => 'Synthetic source',
    'host' => (string) $cfg['host'],
    'port' => (int) $cfg['port'],
    'database' => $sourceDb,
    'username' => (string) $cfg['username'],
    'password' => (string) $cfg['password'],
    'prefix' => 'tbl',
    'tls_mode' => 'none',
    'timeout' => 10,
]);
$config = $connection->config();
$config['allow_insecure'] = true;

try {
    $connector = new WhmcsConnector($config);
    ok($connector->test(), 'Connection test succeeded');
    ok($connector->diagnostics()['tls_active'] === false, 'No-TLS mode reports TLS inactive');
    ok($connector->grants()['database_wide_select'] === true, 'Database-wide SELECT grant recognised');
    $analysis = $analyzer->analyze($connector, $catalog, 'tbl');
    ok($analysis['version'] === '8.10.1', 'WHMCS version detected');
    ok(in_array('clients', $analysis['available_entities'], true), 'Clients entity discovered');
    ok(($analysis['counts']['clients'] ?? 0) === 5, 'Source client count read correctly');
    ok(($analysis['timezone']['source_iana'] ?? null) === 'Europe/Warsaw', 'Source timezone detected');
    ok($analysis['grant_coverage']['status'] === 'full', 'Grant coverage reported as full');
    ok(
        ($analysis['schema_issues'] ?? []) === [],
        'Every declared catalog source column exists'.($analysis['schema_issues'] ? ': '.implode('; ', $analysis['schema_issues']) : '')
    );
} catch (Throwable $e) {
    ok(false, 'Connection diagnostics threw: '.$e->getMessage());
}

$baselineClients = DB::table('clients')->count();
$baselineEmails = DB::table('emails')->count();
$baselineModules = DB::table('module_logs')->count();
$baselineActivity = DB::table('activity_logs')->count();
$baselineMappings = WhmcsToPnlcsMapping::count();

// Dry run writes no business records, mappings or record log.
$dry = $runner->prepare($connection, ['scope' => ['accounts', 'financial'], 'dry_run' => true, 'batch_size' => 2])['run'];
$dryProgress = runToCompletion($runner, $dry);
ok($dryProgress['status'] === 'completed', 'Dry run completed all batches');
ok($dryProgress['percent'] === 100, 'Dry run progress reached 100%');
ok(DB::table('clients')->count() === $baselineClients, 'Dry run wrote no client records');
ok(WhmcsToPnlcsMapping::count() === $baselineMappings, 'Dry run wrote no ID mappings');
ok(WhmcsToPnlcsRecord::count() === 0, 'Dry run wrote no record log');

// Real run: references, preserved numbers, mappings, audit log.
$real = $runner->prepare($connection, ['scope' => ['accounts', 'financial'], 'dry_run' => false, 'batch_size' => 2])['run'];
$realProgress = runToCompletion($runner, $real);
ok($realProgress['status'] === 'completed', 'Real migration completed all batches');
$afterClients = DB::table('clients')->count();
ok($afterClients === $baselineClients + 5, 'Five synthetic clients imported');
ok(WhmcsToPnlcsMapping::where('entity', 'clients')->count() === 5, 'Five persistent client mappings stored');
ok(WhmcsToPnlcsRecord::where('run_id', $real->id)->count() > 0, 'Per-record audit log written');
ok(DB::table('invoices')->where('invoice_num', 'INV-1001')->exists(), 'Historical invoice number preserved');
$invoiceTargets = WhmcsToPnlcsMapping::where('entity', 'invoices')->pluck('target_id');
ok($invoiceTargets->count() === 2 && $invoiceTargets->unique()->count() === 2, 'Blank invoice numbers kept on distinct target rows');
$autoInvoice = DB::table('invoices')->where('source_invoice_id', 2)->first();
ok($autoInvoice !== null && $autoInvoice->invoice_num === '2', 'Blank WHMCS invoice number falls back to source row ID');
ok(DB::table('clients')->where('email', 'client1@example.test')->exists(), 'Source client email preserved');
ok(DB::table('clients')->where('email', 'client1@example.test')->whereNotNull('group_id')->whereNotNull('currency_id')->exists(), 'Client group and currency references resolved');
ok(DB::table('contacts')->where('email', 'contact1@example.test')->whereNotNull('client_id')->exists(), 'Contact client reference resolved');
ok(DB::table('orders')->where('order_num', 'WHMCS-1001')->whereNotNull('client_id')->whereNotNull('invoice_id')->exists(), 'Order resolved to its imported client and invoice');
ok(DB::table('clients')->where('email', 'client5@example.test')->where('last_name', 'Company Five')->exists(), 'Blank source last name falls back to company name');
ok(DB::table('clients')->where('email', 'client5@example.test')->where('phone_number', '+91..822325381725422099000007319035353512')->exists(), 'Over-long source phone number preserved in widened column');

// Idempotency and archive behaviour on a second pass.
$second = $runner->prepare($connection, ['scope' => ['accounts', 'financial', 'support'], 'dry_run' => false, 'batch_size' => 2])['run'];
$secondProgress = runToCompletion($runner, $second);
ok($secondProgress['status'] === 'completed', 'Second migration run completed');
ok(DB::table('clients')->count() === $afterClients, 'Re-import did not duplicate clients');
ok(WhmcsToPnlcsRecord::where('run_id', $second->id)->where('status', 'skipped')->count() > 0, 'Re-import recorded idempotent skips');
ok(DB::table('whmcs_to_pnlcs_archive')->count() >= 1, 'Unsupported history archived');

// A PNLCS shop always has one default currency; the disposable migrations do
// not seed one. Set it after currency import so derived shop prices can be
// calculated. USD is the source's first currency and the shop default here.
DB::table('currencies')->update(['is_default' => 0]);
DB::table('currencies')->where('code', 'USD')->update(['is_default' => 1]);

// Catalogue, services, domains and support history that were previously
// missing because the catalog named non-existent WHMCS columns.
$catalogue = $runner->prepare($connection, ['scope' => ['catalogue', 'support'], 'dry_run' => false, 'batch_size' => 2])['run'];
$catalogueProgress = runToCompletion($runner, $catalogue);
ok($catalogueProgress['status'] === 'completed', 'Catalogue migration completed all batches');
ok(DB::table('products')->whereIn('name', ['Starter', 'Business'])->where('hidden', 0)->where('retired', 1)->count() === 2, 'Products imported visible but non-orderable');
$shopCurrencyId = (int) DB::table('currencies')->where('is_default', 1)->value('id');
$eurCurrencyId = (int) WhmcsToPnlcsMapping::where('entity', 'currencies')->where('source_id', '2')->value('target_id');
$starterId = (int) WhmcsToPnlcsMapping::where('entity', 'products')->where('source_id', '1')->value('target_id');
$businessId = (int) WhmcsToPnlcsMapping::where('entity', 'products')->where('source_id', '2')->value('target_id');
ok($eurCurrencyId > 0 && $eurCurrencyId !== $shopCurrencyId, 'Non-default WHMCS currency mapped separately');
$shopPrice = DB::table('pricing')->where('type', 'product')->where('currency_id', $shopCurrencyId)
    ->where('rel_id', $starterId)->where('monthly', 21.74)->first();
ok($shopPrice !== null, 'Non-default product price copied into the shop currency');
ok($shopPrice !== null && (float) $shopPrice->annually === 217.39, 'Converted annual product price preserved');
ok($shopPrice !== null && (int) $shopPrice->whmcs_source_currency_id === 2, 'Converted price records its source currency');
ok(DB::table('pricing')->where('type', 'product')->where('currency_id', $shopCurrencyId)
    ->where('rel_id', $businessId)->where('monthly', 15.00)->exists(), 'Shop-currency product price was not overwritten');
$clientOneId = (int) DB::table('clients')->where('email', 'client1@example.test')->value('id');
$serviceIds = DB::table('services')->where('client_id', $clientOneId)->orderBy('id')->pluck('id')->all();
ok(count($serviceIds) === 3, 'All three services imported for the client');
ok(count(array_unique($serviceIds)) === 3, 'Each service has its own target row');
ok(DB::table('services')->where('client_id', $clientOneId)->where('status', 'active')->count() === 2, 'WHMCS domainstatus maps to active services');
ok(DB::table('services')->where('client_id', $clientOneId)->where('status', 'suspended')->count() === 1, 'WHMCS suspended status preserved');
ok(DB::table('services')->whereNotNull('whmcs_service_id')->count() === 4, 'Original WHMCS service IDs stored on target rows');
$serviceMappings = WhmcsToPnlcsMapping::where('entity', 'services')->pluck('target_id');
ok($serviceMappings->count() === $serviceMappings->unique()->count(), 'Service mappings point to distinct target IDs');
$clientFiveId = (int) DB::table('clients')->where('email', 'client5@example.test')->value('id');
$eurService = DB::table('services')->where('whmcs_service_id', 4)->first();
ok($eurService !== null && (int) $eurService->client_id === $clientFiveId, 'EUR client service imported');
ok($eurService !== null && (float) $eurService->amount === 108.70, 'Service price converted to the shop currency');
ok($eurService !== null && (float) $eurService->whmcs_original_amount === 100.00, 'Original service price retained for audit');
ok(DB::table('domains')->where('domain', 'example.test')->exists(), 'Domain imported');
$ticket = DB::table('tickets')->where('tid', 'ABC-123')->first();
ok($ticket !== null && (int) $ticket->department_id > 0, 'Ticket department reference resolved');
ok($ticket !== null && $ticket->priority === 'high', 'WHMCS urgency mapped to ticket priority');
ok(DB::table('ticket_replies')->whereNotNull('ticket_id')->count() === 2, 'Both ticket replies linked to their ticket');
ok(DB::table('ticket_replies')->where('message', '(no message)')->exists(), 'Blank ticket reply body stored with placeholder');
ok(DB::table('ticket_notes')->whereNotNull('ticket_id')->count() === 1, 'Ticket note linked to its ticket');
ok(DB::table('ticket_departments')->where('name', 'Billing')->where('clients_only', 1)->exists(), 'Text "on" department flag normalised to 1');
ok(DB::table('ticket_departments')->where('name', 'Support')->where('clients_only', 0)->exists(), 'Blank department flag normalised to 0');
ok(DB::table('transactions')->where('transaction_id', 'TXN-1001')->where('fees', '0.25')->exists(), 'WHMCS fees column imported');
ok(DB::table('transactions')->where('transaction_id', 'TXN-1005')->whereNotNull('client_id')->exists(), 'Transaction for blank-lastname client imported');
ok(DB::table('transactions')->where('transaction_id', 'TXN-1006')->where('date', '1970-01-01')->exists(), 'Zero transaction date mapped to a valid sentinel');
ok(DB::table('transactions')->where('transaction_id', 'TXN-1001')->where('amount_in', '2.00')->exists(), 'Distinct payment with a reused source reference stayed separate');

$catalogueAgain = $runner->prepare($connection, ['scope' => ['catalogue'], 'dry_run' => false, 'batch_size' => 2])['run'];
runToCompletion($runner, $catalogueAgain);
ok(DB::table('services')->where('client_id', $clientOneId)->count() === 3, 'Re-import did not duplicate services');

// Pause / resume.
$pauseRun = $runner->prepare($connection, ['scope' => ['support'], 'dry_run' => true, 'batch_size' => 1])['run'];
$runner->start($pauseRun);
$runner->batch($pauseRun);
$runner->pause($pauseRun);
ok($runner->batch($pauseRun)['status'] === 'paused', 'Paused run ignores additional batch requests');
$runner->resume($pauseRun);
ok($runner->batch($pauseRun)['status'] !== 'paused', 'Resumed run processes further batches');

// A batch-level failure must be visible, not left as a silently interrupted
// "running" run with a buried warning.
$brokenConnection = WhmcsToPnlcsConnection::create([
    'name' => 'Unreachable source',
    'host' => '127.0.0.1',
    'port' => 1,
    'database' => 'whmcs_unreachable',
    'username' => 'nobody',
    'password' => 'wrong',
    'prefix' => 'tbl',
    'tls_mode' => 'none',
    'timeout' => 2,
]);
$brokenRun = $pauseRun->replicate();
$brokenRun->connection_id = $brokenConnection->id;
$brokenRun->unsetRelation('connection'); // replicate() copies loaded relations
$brokenRun->status = 'pending';
$brokenRun->warnings = [];
$brokenRun->entity_index = 0;
$brokenRun->offset = 0;
$brokenRun->finished_at = null;
$brokenRun->save();
$brokenProgress = $runner->batch($brokenRun);
ok($brokenProgress['status'] === 'failed', 'Batch exception marks run as failed');
ok(is_string($brokenProgress['last_error']) && $brokenProgress['last_error'] !== '', 'Batch exception exposes last_error for the UI');
ok($brokenProgress['finished_at'] !== null, 'Failed run records a finish time');

// Reconciliation and reports.
$reconciliation = (new ReconciliationService($catalog))->run($real);
$invoiceRow = collect($reconciliation['rows'] ?? [])->firstWhere('entity', 'invoices');
ok($invoiceRow !== null && abs((float) $invoiceRow['source_total'] - 59.99) < 0.01, 'Source invoice total reconciled');
ok($invoiceRow !== null && abs((float) $invoiceRow['target_total'] - 59.99) < 0.01, 'Destination invoice total reconciled');
$reportService = new ReportService(new ReconciliationService($catalog));
$csv = $reportService->output($reportService->build($real, 'financial', 'csv'));
ok(str_contains($csv, 'entity') && str_contains($csv, 'invoices'), 'CSV report generated');
$json = json_decode($reportService->output($reportService->build($real, 'summary', 'json')), true);
ok(is_array($json) && isset($json['run']['id']), 'JSON report generated');

// No automation side effects.
ok(DB::table('emails')->count() === $baselineEmails, 'No emails queued by the migration');
ok(DB::table('module_logs')->count() === $baselineModules, 'No provisioning/module calls made');
ok(DB::table('activity_logs')->count() === $baselineActivity, 'No operational activity log entries created');

// cPanel-style SELECT-only grant and TLS fail-closed behaviour.
try {
    $admin = new PDO(
        sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $cfg['host'], $cfg['port']),
        (string) $cfg['username'],
        (string) $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $admin->exec("DROP USER IF EXISTS 'wtp_readonly'@'localhost'");
    $admin->exec("CREATE USER 'wtp_readonly'@'localhost' IDENTIFIED BY 'read123'");
    $admin->exec('GRANT SELECT ON `'.$sourceDb.'`.* TO \'wtp_readonly\'@\'localhost\'');
    $admin->exec('FLUSH PRIVILEGES');

    $readonly = new WhmcsConnector([
        'host' => (string) $cfg['host'], 'port' => (int) $cfg['port'], 'database' => $sourceDb,
        'username' => 'wtp_readonly', 'password' => 'read123', 'prefix' => 'tbl',
        'tls_mode' => 'none', 'allow_insecure' => true,
    ]);
    ok($readonly->grants()['database_wide_select'] === true, 'cPanel-style database-wide SELECT grant recognised');
    ok($readonly->readable('tblclients'), 'Client table readable with database-scoped grant');
    ok(! method_exists($readonly, 'insert') && ! method_exists($readonly, 'update') && ! method_exists($readonly, 'delete'), 'Connector exposes no write methods');

    foreach (['verified', 'unverified'] as $mode) {
        try {
            new WhmcsConnector([
                'host' => (string) $cfg['host'], 'port' => (int) $cfg['port'], 'database' => $sourceDb,
                'username' => 'wtp_readonly', 'password' => 'read123', 'prefix' => 'tbl', 'tls_mode' => $mode,
            ]);
            ok(false, "$mode mode must not silently succeed without TLS");
        } catch (Throwable $e) {
            ok(str_contains($e->getMessage(), 'TLS') || str_contains($e->getMessage(), 'certificate'), "$mode mode failed closed");
        }
    }

    try {
        new WhmcsConnector([
            'host' => (string) $cfg['host'], 'port' => (int) $cfg['port'], 'database' => $sourceDb,
            'username' => 'wtp_readonly', 'password' => 'read123', 'prefix' => 'tbl', 'tls_mode' => 'none',
        ]);
        ok(false, 'No-TLS mode must require confirmation');
    } catch (Throwable $e) {
        ok(str_contains($e->getMessage(), 'explicit administrator confirmation'), 'No-TLS mode requires explicit confirmation');
    }
} catch (Throwable $e) {
    ok(false, 'Grant/TLS tests threw: '.$e->getMessage());
}

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
