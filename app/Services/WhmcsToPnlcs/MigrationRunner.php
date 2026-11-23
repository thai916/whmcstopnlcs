<?php

namespace App\Services\WhmcsToPnlcs;

use App\Models\WhmcsToPnlcsConnection;
use App\Models\WhmcsToPnlcsRecord;
use App\Models\WhmcsToPnlcsRun;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Chunked, resumable migration orchestration. No request ever imports everything. */
class MigrationRunner
{
    public function __construct(
        protected SourceCatalog $catalog,
        protected SourceAnalyzer $analyzer,
        protected SafetyGuard $safety,
        protected EntityImporter $importer,
    ) {}

    /** @return array{run: WhmcsToPnlcsRun, analysis: array<string, mixed>} */
    public function prepare(WhmcsToPnlcsConnection $connection, array $input): array
    {
        $config = $connection->config();
        if (($config['tls_mode'] ?? '') === 'none') {
            // The administrator confirmed this when the connection was saved.
            $config['allow_insecure'] = true;
        }
        $connector = new WhmcsConnector($config);
        $connector->test();
        $analysis = $this->analyzer->analyze($connector, $this->catalog, $config['prefix']);

        $scope = $input['scope'] ?? [];
        if (! is_array($scope) || $scope === []) {
            $scope = ['full'];
        }

        $selected = [];
        foreach ($scope as $key) {
            $group = $this->catalog->scopeGroups()[$key] ?? null;
            if ($group) {
                foreach ($group['entities'] as $entity) {
                    $selected[$entity] = true;
                }
            } elseif ($this->catalog->get($key)) {
                $selected[$key] = true;
            }
        }

        $entities = array_values(array_intersect(SourceCatalog::ORDER, array_keys($selected)));
        foreach ($analysis['extra_archive_tables'] ?? [] as $extra) {
            if (! empty($input['archive_extras'])) {
                $entities[] = 'archive:'.$extra['table'];
            }
        }

        $entities = array_values(array_filter($entities, function (string $entity) use ($analysis) {
            if (str_starts_with($entity, 'archive:')) {
                return true;
            }

            return in_array($entity, $analysis['available_entities'], true);
        }));

        $dryRun = (bool) ($input['dry_run'] ?? false);
        $totals = [];
        foreach ($entities as $entity) {
            $totals[$entity] = str_starts_with($entity, 'archive:')
                ? ($analysis['extra_archive_tables'][array_search(substr($entity, 8), array_column($analysis['extra_archive_tables'], 'table'))]['count'] ?? 0)
                : ($analysis['counts'][$entity] ?? 0);
        }

        $duplicateStrategy = $input['duplicate_strategy'] ?? 'skip';
        $options = [
            'duplicate_strategy' => in_array($duplicateStrategy, ['skip', 'update', 'flag'], true)
                ? $duplicateStrategy
                : 'skip',
            'timezone_override' => $input['timezone_override'] ?? null,
            'no_tls_confirmation' => ($connection->tls_mode ?? '') === 'none' || (bool) ($input['allow_insecure'] ?? false),
        ];

        $run = WhmcsToPnlcsRun::create([
            'connection_id' => $connection->id,
            'name' => $input['name'] ?? null,
            'scope' => $scope,
            'options' => $options,
            'dry_run' => $dryRun,
            'status' => 'pending',
            'entities' => $entities,
            'entity_index' => 0,
            'offset' => 0,
            'batch_size' => max(1, min((int) ($input['batch_size'] ?? 100), 1000)),
            'totals' => $totals,
            'processed' => [],
            'counts' => [],
            'source_fingerprint' => $analysis['fingerprint'],
            'source_info' => [
                'version' => $analysis['version'],
                'server' => $analysis['server'],
                'grant_coverage' => $analysis['grant_coverage'],
                'table_count' => $analysis['table_count'],
            ],
            'timezone' => $analysis['timezone'],
            'safety' => $this->safety->check(),
            'warnings' => array_map(
                static fn (string $issue): string => 'Source catalog mismatch: '.$issue,
                (array) ($analysis['schema_issues'] ?? [])
            ),
            'admin_id' => auth('admin')->id(),
        ]);

        return ['run' => $run, 'analysis' => $analysis];
    }

    public function start(WhmcsToPnlcsRun $run): void
    {
        if (! in_array($run->status, ['pending', 'paused'], true)) {
            return;
        }

        $run->update([
            'status' => 'running',
            'started_at' => $run->started_at ?? now(),
            'finished_at' => null,
        ]);
    }

    /** Process one bounded batch and return progress for AJAX polling. */
    public function batch(WhmcsToPnlcsRun $run): array
    {
        if ($run->status === 'paused' || $run->status === 'cancelled' || $run->status === 'completed') {
            return $this->progress($run);
        }

        if ($run->status !== 'running') {
            $this->start($run);
        }

        $connection = $run->connection;
        if (! $connection) {
            $run->update(['status' => 'failed']);

            return $this->progress($run);
        }

        $config = $connection->config();
        if (($config['tls_mode'] ?? '') === 'none' || ($run->options['no_tls_confirmation'] ?? false)) {
            $config['allow_insecure'] = true;
        }

        $ctx = null;
        $budget = max(1, (int) $run->batch_size);
        $processed = 0;

        try {
            $connector = new WhmcsConnector($config);
            $ctx = new ImportContext($run, $connector, (string) $run->source_fingerprint, $run->options ?? []);
            $entities = $run->entities ?? [];

            while ($processed < $budget && $run->entity_index < count($entities)) {
                $entity = (string) $entities[$run->entity_index];
                [$table, $catalogEntity] = $this->resolveEntity($entity, $config['prefix']);

                $rows = $connector->page($table, min($budget - $processed, (int) $run->batch_size), (int) $run->offset);

                foreach ($rows as $row) {
                    $status = 'failed';
                    $targetId = null;
                    $error = null;

                    if (str_starts_with($entity, 'archive:')) {
                        $ctx->archive('other_history', $row['id'] ?? null, $row, $row['date'] ?? null);
                        $status = $ctx->dryRun() ? 'would_archive' : 'archived';
                    } else {
                        $result = $this->importer->import($catalogEntity, $row, $ctx, $this->catalog);
                        $status = $result['status'];
                        $targetId = $result['target_id'];
                        $error = $result['error'];
                    }

                    $this->record($run, $entity, $row['id'] ?? null, $status, $targetId, $error);
                    $processed++;
                }

                $run->offset += count($rows);
                if (count($rows) < min($budget - $processed, (int) $run->batch_size) || $run->offset >= (int) ($run->totals[$entity] ?? PHP_INT_MAX)) {
                    $run->entity_index++;
                    $run->offset = 0;
                }
            }

            $isDone = $run->entity_index >= count($entities);
            $run->update([
                'entity_index' => $run->entity_index,
                'offset' => $run->offset,
                // Clear a previous interruption once a batch succeeds, so a
                // resolved source problem does not look like an ongoing error.
                'warnings' => array_values(array_unique(array_merge(
                    array_values(array_filter(
                        $run->warnings ?? [],
                        fn ($warning) => ! str_starts_with((string) $warning, 'Batch interrupted:'),
                    )),
                    $ctx->warnings,
                ))),
                'status' => $isDone ? 'completed' : 'running',
                'finished_at' => $isDone ? now() : null,
            ]);
        } catch (Throwable $e) {
            // A batch-level exception means no rows from that batch were
            // processed. Fail the run visibly so the administrator can fix the
            // source connection and click Start / continue to retry.
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'warnings' => array_values(array_unique(array_merge($run->warnings ?? [], ['Batch interrupted: '.$e->getMessage()]))),
            ]);
        }

        return $this->progress($run);
    }

    protected function resolveEntity(string $entity, string $prefix): array
    {
        if (str_starts_with($entity, 'archive:')) {
            return [substr($entity, 8), 'other_history'];
        }

        return [(string) $this->catalog->sourceTable($entity, $prefix), $entity];
    }

    protected function record(WhmcsToPnlcsRun $run, string $entity, mixed $sourceId, string $status, ?int $targetId, ?string $error): void
    {
        if ($run->dry_run) {
            return;
        }

        $record = WhmcsToPnlcsRecord::updateOrCreate([
            'run_id' => $run->id,
            'entity' => $entity,
            'source_id' => (string) ($sourceId ?? ''),
        ], [
            'target_id' => $targetId,
            'status' => $status,
            'error' => $error,
        ]);

        if ($record->wasRecentlyCreated === false) {
            $record->increment('retry_count');
        }
    }

    public function progress(WhmcsToPnlcsRun $run): array
    {
        $run->refresh();
        $counts = [];
        if (! $run->dry_run) {
            foreach (DB::table('whmcs_to_pnlcs_records')
                ->where('run_id', $run->id)
                ->select('entity', 'status', DB::raw('COUNT(*) as total'))
                ->groupBy('entity', 'status')
                ->get() as $row) {
                $counts[$row->entity][$row->status] = (int) $row->total;
            }
        }

        $totals = $run->totals ?? [];
        $done = 0;
        $expected = 0;
        foreach ($totals as $entity => $total) {
            $expected += (int) $total;
        }

        if ($run->dry_run) {
            // Dry runs store no per-row records, so report from the checkpoint.
            $index = 0;
            foreach ($totals as $entity => $total) {
                if ($index < (int) $run->entity_index) {
                    $done += (int) $total;
                } elseif ($index === (int) $run->entity_index) {
                    $done += min((int) $run->offset, (int) $total);
                }
                $index++;
            }
        } else {
            foreach ($totals as $entity => $total) {
                $entityCounts = $counts[$entity] ?? [];
                unset($entityCounts['failed']);
                $done += array_sum($entityCounts);
            }
        }

        $warnings = $run->warnings ?? [];
        $lastError = null;
        foreach (array_reverse($warnings) as $warning) {
            if (str_starts_with((string) $warning, 'Batch interrupted:')) {
                $lastError = trim(substr((string) $warning, strlen('Batch interrupted:')));
                break;
            }
        }

        return [
            'id' => $run->id,
            'status' => $run->status,
            'dry_run' => $run->dry_run,
            'current_entity' => $run->entities[$run->entity_index] ?? null,
            'entity_index' => $run->entity_index,
            'entity_total' => count($run->entities ?? []),
            'offset' => $run->offset,
            'expected' => $expected,
            'done' => min($done, $expected),
            'percent' => $expected > 0 ? min(100, (int) round($done / $expected * 100)) : 100,
            'counts' => $counts,
            'warnings' => $run->warnings ?? [],
            'last_error' => $lastError,
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    public function pause(WhmcsToPnlcsRun $run): void
    {
        if ($run->status === 'running') {
            $run->update(['status' => 'paused']);
        }
    }

    public function resume(WhmcsToPnlcsRun $run): void
    {
        if ($run->status === 'paused') {
            $run->update(['status' => 'running']);
        }
    }

    public function cancel(WhmcsToPnlcsRun $run): void
    {
        if (! in_array($run->status, ['completed', 'failed'], true)) {
            $run->update(['status' => 'cancelled', 'finished_at' => now()]);
        }
    }

    /**
     * Queue failed rows for another pass. The checkpoint is rewound to the
     * start: every source row is scanned again, but the persistent ID mapping
     * makes already-imported rows idempotent skips, so only the failed rows
     * (and anything skipped because a prerequisite was missing) are retried.
     */
    public function retryFailed(WhmcsToPnlcsRun $run): int
    {
        $count = WhmcsToPnlcsRecord::where('run_id', $run->id)->where('status', 'failed')->delete();

        $run->update([
            'status' => 'pending',
            'entity_index' => 0,
            'offset' => 0,
            'finished_at' => null,
        ]);

        return $count;
    }
}
