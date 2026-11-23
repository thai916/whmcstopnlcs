<?php

namespace App\Services\WhmcsToPnlcs;

use App\Models\WhmcsToPnlcsMapping;
use App\Models\WhmcsToPnlcsRecord;
use App\Models\WhmcsToPnlcsReport;
use App\Models\WhmcsToPnlcsRun;
use Illuminate\Support\Facades\DB;

/** Builds downloadable, administrator-only migration reports. */
class ReportService
{
    public function __construct(protected ReconciliationService $reconciliation) {}

    public function build(WhmcsToPnlcsRun $run, string $type, string $format = 'csv'): WhmcsToPnlcsReport
    {
        $data = match ($type) {
            'summary' => $this->summary($run),
            'records' => $this->records($run),
            'mappings' => $this->mappings($run),
            'financial' => $this->reconciliation->run($run),
            'warnings' => $this->warnings($run),
            default => ['error' => 'Unknown report type'],
        };

        return WhmcsToPnlcsReport::create([
            'run_id' => $run->id,
            'type' => $type,
            'format' => in_array($format, ['csv', 'json'], true) ? $format : 'csv',
            'filename' => sprintf('whmcs-to-pnlcs-run%d-%s.%s', $run->id, $type, $format),
            'data' => $data,
            'admin_id' => auth('admin')->id(),
        ]);
    }

    public function output(WhmcsToPnlcsReport $report): string
    {
        if ($report->format === 'json') {
            return json_encode($report->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        $rows = $this->flatten($report->data);
        if ($rows === []) {
            return '';
        }

        $columns = array_values(array_unique(array_reduce($rows, fn ($carry, $row) => array_merge($carry, array_keys($row)), [])));
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $columns);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($column) => $row[$column] ?? '', $columns));
        }
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content ?: '';
    }

    /** @return array<string, mixed> */
    protected function summary(WhmcsToPnlcsRun $run): array
    {
        $rows = DB::table('whmcs_to_pnlcs_records')
            ->where('run_id', $run->id)
            ->select('entity', 'status', DB::raw('COUNT(*) as total'))
            ->groupBy('entity', 'status')
            ->get();

        return [
            'run' => [
                'id' => $run->id, 'status' => $run->status, 'dry_run' => $run->dry_run,
                'started_at' => $run->started_at, 'finished_at' => $run->finished_at,
                'source_fingerprint' => $run->source_fingerprint,
            ],
            'totals' => $run->totals,
            'by_status' => $rows->map(fn ($row) => [
                'entity' => $row->entity, 'status' => $row->status, 'total' => (int) $row->total,
            ])->all(),
            'warnings' => $run->warnings ?? [],
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function records(WhmcsToPnlcsRun $run): array
    {
        return WhmcsToPnlcsRecord::where('run_id', $run->id)
            ->orderBy('entity')->orderBy('source_id')
            ->get(['entity', 'source_id', 'target_id', 'status', 'error', 'retry_count', 'created_at'])
            ->map(fn ($record) => [
                'entity' => $record->entity, 'source_id' => $record->source_id,
                'target_id' => $record->target_id, 'status' => $record->status,
                'error' => $record->error, 'retry_count' => $record->retry_count,
                'created_at' => (string) $record->created_at,
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    protected function mappings(WhmcsToPnlcsRun $run): array
    {
        return WhmcsToPnlcsMapping::where('source_fingerprint', $run->source_fingerprint)
            ->orderBy('entity')->orderBy('source_id')
            ->get(['entity', 'source_id', 'target_id', 'target_table', 'payload_hash', 'status'])
            ->map(fn ($map) => [
                'entity' => $map->entity, 'source_id' => $map->source_id,
                'target_id' => $map->target_id, 'target_table' => $map->target_table,
                'payload_hash' => $map->payload_hash, 'status' => $map->status,
            ])->all();
    }

    /** @return array<string, mixed> */
    protected function warnings(WhmcsToPnlcsRun $run): array
    {
        return [
            'timezone' => $run->timezone,
            'safety' => $run->safety,
            'warnings' => $run->warnings ?? [],
            'post_migration_checklist' => SafetyGuard::checklist(),
        ];
    }

    protected function flatten(array $data): array
    {
        if (isset($data[0]) && is_array($data[0])) {
            return $data;
        }

        $rows = [];
        foreach ($data as $key => $value) {
            if (is_array($value) && ! array_is_list($value)) {
                foreach ($value as $child => $grandchild) {
                    if (is_array($grandchild)) {
                        $rows[] = ['section' => $key.'.'.$child, 'value' => json_encode($grandchild)];
                    } else {
                        $rows[] = ['section' => $key.'.'.$child, 'value' => $grandchild];
                    }
                }
            } else {
                $rows[] = ['section' => $key, 'value' => is_array($value) ? json_encode($value) : $value];
            }
        }

        return $rows;
    }
}
