<?php

namespace App\Services\WhmcsImport;

use RuntimeException;
use Throwable;

/**
 * Historical extension beside ClientImporter/ServiceImporter/DomainImporter.
 * First increment archives safe fields; does not create operational entities.
 */
final class HistoryImporter
{
    public function __construct(private ?HistoryStore $store = null) {}

    public function inventory(HistorySource $source): array
    {
        if ($source instanceof WhmcsConnector) {
            $source->requireReadOnlySession();
        }
        $catalog = array_column(HistoryCatalog::definitions(), null, 'table');
        $result = [];
        foreach ($source->tables() as $table) {
            $columns = array_column($source->columns($table), 'name');
            $entry = $catalog[$table] ?? null;
            $result[$table] = [
                'count' => $source->count($table),
                'disposition' => $entry && in_array('id', $columns, true) ? 'partial_history_supported' : 'unsupported',
                'included_columns' => $entry ? array_values(array_intersect($entry['fields'], $columns)) : [],
                'excluded_columns' => $entry ? array_values(array_diff($columns, $entry['fields'])) : $columns,
            ];
        }

        return ['tables' => $result, 'blockers' => HistoryCatalog::blockers()];
    }

    /**
     * Dry run never constructs tracking records, writes files, or mutates data.
     * Snapshot key identifies an immutable backup, not a live database.
     */
    public function run(
        HistorySource $source,
        string $sourceKey,
        string $snapshotKey,
        array $categories,
        bool $dryRun = true,
        ?int $resume = null,
        int $batchSize = 250,
    ): array {
        $definitions = HistoryCatalog::definitions();
        foreach ($categories as $category) {
            if (! isset($definitions[$category])) {
                throw new RuntimeException('Unknown historical category.');
            }
        }
        if ($categories === [] || count(array_unique($categories)) !== count($categories)) {
            throw new RuntimeException('Choose distinct historical categories.');
        }
        if ($source instanceof WhmcsConnector) {
            $source->requireReadOnlySession();
        }
        $run = null;
        $token = '';
        $checkpoints = [];
        if (! $dryRun) {
            if (! $this->store) {
                throw new RuntimeException('Historical store is required for commit.');
            }
            $run = $resume ?? $this->store->start($sourceKey, $snapshotKey, $categories);
            [$token, $checkpoints] = $this->store->claim($run, $sourceKey, $snapshotKey, $categories);
        }
        $report = ['run_id' => $run, 'dry_run' => $dryRun, 'native_records_written' => 0,
            'categories' => [], 'blockers' => HistoryCatalog::blockers()];
        $tables = $source->tables();
        try {
            foreach ($categories as $category) {
                $entry = $definitions[$category];
                $table = $entry['table'];
                $stats = ['source_count' => 0, 'examined' => 0, 'archived' => 0, 'unchanged' => 0,
                    'failed' => 0, 'would_archive' => 0, 'totals' => [], 'excluded_columns' => []];
                if (! in_array($table, $tables, true)) {
                    $report['categories'][$category] = $stats + ['state' => 'missing_table'];
                    continue;
                }
                $columns = array_column($source->columns($table), 'name');
                if (! in_array('id', $columns, true)) {
                    throw new RuntimeException('Category lacks verified id paging.');
                }
                $fields = array_values(array_intersect($entry['fields'], $columns));
                $stats['excluded_columns'] = array_values(array_diff($columns, $fields));
                $stats['source_count'] = $source->count($table);
                // Reconciliation always scans from zero, including records already
                // checkpointed. This detects changed snapshot content on resume.
                $after = '0';
                do {
                    $rows = $source->historyPage($table, $fields, $after, $batchSize);
                    foreach ($rows as $raw) {
                        $row = array_intersect_key($raw, array_flip($fields));
                        $id = (string) ($row['id'] ?? '');
                        if (! ctype_digit($id) || (int) $id <= (int) $after) {
                            throw new RuntimeException('Source paging is not strictly increasing.');
                        }
                        $after = $id;
                        $stats['examined']++;
                        try {
                            $currency = (string) ($row['currency'] ?? 'unknown');
                            $stats['totals'][$currency] ??= [];
                            $this->validateAndTotal($row, $stats['totals'][$currency]);
                            $references = [];
                            foreach ($entry['references'] as $field => $target) {
                                if (isset($row[$field]) && (string) $row[$field] !== '0' && (string) $row[$field] !== '') {
                                    $references[$field] = ['table' => $target, 'id' => (string) $row[$field], 'evidence' => 'expected_unverified'];
                                }
                            }
                            if ($dryRun) {
                                $stats['would_archive']++;
                            } else {
                                $outcome = $this->store->save($run, $token, $sourceKey, $table, $row, $references);
                                $stats[$outcome]++;
                            }
                        } catch (Throwable $e) {
                            $stats['failed']++;
                            if (! $dryRun) {
                                $this->store->issue($run, $token, $table, $id,
                                    $e->getMessage() === 'SOURCE_OR_ARCHIVE_CHANGED' ? 'source_or_archive_changed' : 'record_failed');
                            }
                        }
                    }
                    if (! $dryRun) {
                        $checkpoints[$table] = $after;
                        $this->store->checkpoint($run, $token, $checkpoints);
                    }
                } while (count($rows) === max(1, min(1000, $batchSize)));
                $stats['state'] = $stats['failed'] > 0 ? 'partial' : 'historical_read_only';
                $stats['count_matches'] = $stats['examined'] === $stats['source_count'];
                $stats['reconciliation'] = 'Safe-column payload SHA-256 checked on every persisted row. Totals grouped by explicit source currency; unknown is NOT a reconciled currency. Not operational financial reconciliation.';
                $report['categories'][$category] = $stats;
            }
            if (! $dryRun) {
                if ($this->store->profileSchemaInstalled()) {
                    $report['profile_ownership'] = $this->store->rebuildOwnership($sourceKey);
                }
                $report['archive_audit'] = $this->store->reconcile($sourceKey,
                    array_map(fn ($category) => $definitions[$category]['table'], $categories));
                foreach ($report['categories'] as $category => &$stats) {
                    $saved = $report['archive_audit']['tables'][$definitions[$category]['table']] ?? ['count' => 0, 'totals' => []];
                    $stats['archive_count_matches'] = $saved['count'] === $stats['source_count'];
                    $stats['archive_totals_match'] = $saved['totals'] == array_filter($stats['totals'], fn ($totals) => $totals !== []);
                }
                unset($stats);
                $complete = array_all($report['categories'], fn ($s) => ($s['state'] ?? '') === 'historical_read_only'
                    && ($s['count_matches'] ?? false) && ($s['archive_count_matches'] ?? false)
                    && ($s['archive_totals_match'] ?? false));
                $complete = $complete && $report['archive_audit']['checksum_failures'] === 0
                    && $report['archive_audit']['owner_conflicts'] === 0
                    && $report['archive_audit']['missing_expected_links'] === 0;
                $complete = $complete && ($report['profile_ownership']['withheld'] ?? 0) === 0;
                $report['state'] = $complete ? 'archived' : 'partial';
                $this->store->finish($run, $token, $report['state'], $report);
            }
        } catch (Throwable $e) {
            if (! $dryRun) {
                $this->store->finish($run, $token, 'interrupted');
            }
            throw $e;
        }

        return $report;
    }

    private function validateAndTotal(array $row, array &$totals): void
    {
        $scales = ['amount' => 2, 'amountin' => 2, 'amountout' => 2, 'fees' => 2,
            'credit' => 2, 'subtotal' => 2, 'tax' => 2, 'tax2' => 2, 'total' => 2,
            'firstpaymentamount' => 2, 'recurringamount' => 2, 'setupfee' => 2, 'recurring' => 2];
        foreach ($scales as $field => $scale) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                // Float inputs are already lossy. Fail instead of disguising them.
                if (is_float($row[$field])) {
                    throw new RuntimeException('Floating-point source money is not accepted.');
                }
                $totals[$field] = ExactAmount::add($totals[$field] ?? '0', (string) $row[$field], $scale);
            }
        }
    }
}
