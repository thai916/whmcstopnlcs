#!/usr/bin/env php
<?php

// Standalone isolated integration harness. Does NOT bootstrap the application,
// load .env, contact MySQL, run production migrations or use tests/Pest.php.
use App\Services\WhmcsImport\DomainImporter;
use App\Services\WhmcsImport\ExactAmount;
use App\Services\WhmcsImport\HistoryArchive;
use App\Services\WhmcsImport\HistoryCatalog;
use App\Services\WhmcsImport\HistoryImporter;
use App\Services\WhmcsImport\HistorySource;
use App\Services\WhmcsImport\HistoryStore;
use App\Services\WhmcsImport\SampleSanitizer;
use App\Services\WhmcsImport\ServiceImporter;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Encryption\Encrypter;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$count = 0;
function check(bool $condition, string $label): void
{
    global $count;
    if (! $condition) {
        throw new RuntimeException('FAIL: '.$label);
    }
    $count++;
    echo "PASS: $label\n";
}
function rejects(callable $operation, string $label): void
{
    try {
        $operation();
    } catch (Throwable) {
        check(true, $label);

        return;
    }
    check(false, $label);
}

$fixture = require __DIR__.'/fixtures.php';
$source = new class($fixture) implements HistorySource
{
    public ?int $failPage = null;
    public int $pages = 0;

    public function __construct(public array $data) {}

    public function tables(): array { return array_keys($this->data); }
    public function columns(string $table): array
    {
        $names = array_unique(array_merge(...array_map('array_keys', $this->data[$table])));

        return array_map(fn ($name) => ['name' => $name, 'type' => 'text'], $names);
    }
    public function count(string $table): int { return count($this->data[$table]); }
    public function historyPage(string $table, array $columns, string $after, int $size): array
    {
        $this->pages++;
        if ($this->failPage === $this->pages) {
            throw new RuntimeException('Synthetic interruption');
        }
        $rows = array_filter($this->data[$table], fn ($row) => $row['id'] > (int) $after);

        return array_map(fn ($r) => array_intersect_key($r, array_flip($columns)), array_slice(array_values($rows), 0, max(1, min(1000, $size))));
    }
};

check(ExactAmount::add('99999999999999.99', '0.01') === '100000000000000.00', 'large exact amount without float overflow');
check(ExactAmount::add('-20.00', '12.35') === '-7.65', 'signed credit arithmetic');
check(ExactAmount::add('-20.00', '20.00') === '0.00', 'normalized zero');
rejects(fn () => ExactAmount::normalize('1.001'), 'never silently round money');
rejects(fn () => ExactAmount::normalize('1e2'), 'reject scientific notation');

$db = new Manager;
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
$connection = $db->getConnection();
$migration = require dirname(__DIR__).'/database/2026_11_17_000001_create_whmcs_history_tables.php';
$migration::create($connection->getSchemaBuilder());
$profileMigration = require dirname(__DIR__, 4).'/database/migrations/2026_11_17_000002_create_whmcs_history_profile_links.php';
$profileMigration::create($connection->getSchemaBuilder());
$store = new HistoryStore($connection, new Encrypter(random_bytes(32), 'AES-256-CBC'));
$importer = new HistoryImporter($store);
$categories = array_keys(array_filter(HistoryCatalog::definitions(), fn ($d) => isset($fixture[$d['table']])));
$dry = $importer->run($source, 'synthetic', 'immutable-fixture-v1', $categories);
check($dry['dry_run'] && $connection->table('whmcs_history_runs')->count() === 0, 'dry run writes no run/mapping/archive data');
$report = $importer->run($source, 'synthetic', 'immutable-fixture-v1', $categories, false, batchSize: 2);
check($report['native_records_written'] === 0, 'historical writes create no operational billing records');
check($report['categories']['transactions']['totals']['1']['amountin'] === '230.00', 'exact transaction inflow grouped by source currency');
check($report['categories']['transactions']['totals']['1']['amountout'] === '100.00', 'refund outflow preserved separately');
check($report['categories']['transactions']['archive_totals_match'], 'archive financial values reconcile exactly to scanned safe fields');
check($report['archive_audit']['missing_expected_links'] === 0 && $report['archive_audit']['owner_conflicts'] === 0, 'expected parent links and invoice owners reconcile');
$record = $store->find('synthetic', 'tblinvoices', '81');
$payload = json_decode($store->decode($record), true);
check($payload['invoicenum'] === 'INV-2015/0001' && $payload['datepaid'] === '2015-03-06 23:14:59', 'original invoice number and raw payment time preserved');
check(! str_contains($record->payload, 'INV-2015'), 'archive encrypted at rest');
$mail = json_decode($store->decode($store->find('synthetic', 'tblemails', '191')), true);
check(! isset($mail['message_data']) && $mail['pending'] === 1, 'opaque mail secrets excluded and historical pending retained without send');
$before = $connection->table('whmcs_history_records')->count();
$rerun = $importer->run($source, 'synthetic', 'immutable-fixture-v1', $categories, false);
check($connection->table('whmcs_history_records')->count() === $before, 're-run does not duplicate any archive identity');
check($rerun['categories']['invoices']['unchanged'] === 3, 'existing records checked instead of overwritten');
rejects(fn () => $importer->run($source, 'synthetic', 'snapshot', ['does-not-exist']), 'unsupported categories never fall back to client importer');
$leaseRun = $store->start('lease-test', 'snapshot', ['clients']);
[$leaseToken] = $store->claim($leaseRun, 'lease-test', 'snapshot', ['clients']);
rejects(fn () => $store->claim($leaseRun, 'lease-test', 'snapshot', ['clients']), 'concurrent run lease rejected');
$store->finish($leaseRun, $leaseToken, 'pending');
rejects(fn () => $importer->run($source, 'other-source', 'immutable-fixture-v1', $categories, false, $report['run_id']), 'resume identity mismatch rejected');

$source->data['tblaccounts'][0]['amountin'] = '999.00';
$changed = $importer->run($source, 'synthetic', 'changed-fixture', ['transactions'], false);
check($changed['categories']['transactions']['failed'] === 1, 'source changes conflict rather than overwrite financial history');
check($connection->table('whmcs_history_issues')->where('code', 'source_or_archive_changed')->count() === 1, 'redacted durable per-record issue recorded');
$source->data = $fixture;
$retry = $importer->run($source, 'synthetic', 'changed-fixture', ['transactions'], false, $changed['run_id']);
check($retry['categories']['transactions']['failed'] === 0, 'failed record replay recovers after evidence corrected');
$source->data['tblaccounts'][0]['amountin'] = 40.0;
$invalid = $importer->run($source, 'float-test', 'snapshot', ['transactions'], false);
check($invalid['categories']['transactions']['failed'] === 1, 'lossy floating-point money rejected per record without aborting independent rows');
$source->data = $fixture;

$source->pages = 0;
$source->failPage = 2;
rejects(fn () => $importer->run($source, 'interrupted', 'snapshot', ['transactions'], false, batchSize: 2), 'simulated source interruption is surfaced');
$interrupted = $connection->table('whmcs_history_runs')->where('source_key', 'interrupted')->first();
check($interrupted->state === 'interrupted', 'interruption checkpoint/run state survives');
$source->failPage = null;
$recovered = $importer->run($source, 'interrupted', 'snapshot', ['transactions'], false, $interrupted->id, 2);
check($recovered['categories']['transactions']['unchanged'] === 2 && $recovered['categories']['transactions']['archived'] === 3, 'resume preserves earlier batch and completes remaining records');

$page = (new HistoryArchive($store))->clientPage('synthetic', '41', true);
check(str_contains($page, 'Internal only') && ! str_contains($page, '<script>'), 'admin archive exposes internal history and escapes hostile content');
rejects(fn () => (new HistoryArchive($store))->clientPage('synthetic', '41', false), 'non-admin archive access denied');

foreach ([DomainImporter::class, ServiceImporter::class] as $class) {
    $object = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($class, 'normalizeDates');
    check($method->invoke($object, ['notes' => 'keep dates']) === ['notes' => 'keep dates'], "$class does not null omitted dates");
}
$sanitizer = new SampleSanitizer('test-key-not-production');
$clean = $sanitizer->row(['id' => 41, 'amount' => '100.01', 'date' => '2015-01-01', 'message' => 'password=SECRET', 'email' => 'real@example.com'], ['id' => 'int(10)', 'amount' => 'decimal(16,2)', 'date' => 'date']);
check($clean['amount'] === '100.01' && $clean['id'] === 41 && $clean['date'] === '2015-01-01', 'diagnostic sanitization preserves exact money, IDs and dates');
check(! str_contains(json_encode($clean), 'SECRET') && ! str_contains(json_encode($clean), 'real@example.com'), 'diagnostic free-text and email removed');

// Optional authoritative-schema assertion, no SQL execution.
require dirname(__DIR__, 4).'/tests/WhmcsHistory/profile-integration.php';
if (isset($argv[1])) {
    $sql = file_get_contents($argv[1]);
    preg_match_all('/CREATE TABLE `([^`]+)` \((.*?)\) ENGINE=/s', $sql, $matches, PREG_SET_ORDER);
    $schema = [];
    foreach ($matches as $match) {
        preg_match_all('/^\s+`([^`]+)`/m', $match[2], $columns);
        $schema[$match[1]] = $columns[1];
    }
    check(count($schema) === 173, 'authoritative export has 173 tables');
    foreach (HistoryCatalog::definitions() as $definition) {
        check(isset($schema[$definition['table']]) && array_diff($definition['fields'], $schema[$definition['table']]) === [], 'catalog matches actual schema: '.$definition['table']);
    }
    foreach ($fixture as $table => $rows) {
        check(array_all($rows, fn ($row) => array_diff(array_keys($row), $schema[$table]) === []), 'synthetic fixture columns match schema: '.$table);
    }
}
echo "$count assertions passed. SQLite memory only; no application bootstrap or production connection.\n";
