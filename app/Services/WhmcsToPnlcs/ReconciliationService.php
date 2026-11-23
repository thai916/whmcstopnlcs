<?php

namespace App\Services\WhmcsToPnlcs;

use App\Models\WhmcsToPnlcsRun;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Compare source and destination counts and financial totals. Decimal-safe. */
class ReconciliationService
{
    public function __construct(protected SourceCatalog $catalog) {}

    /** @return array<string, mixed> */
    public function run(WhmcsToPnlcsRun $run): array
    {
        $config = $run->connection?->config();
        if (! $config) {
            return ['error' => 'Connection no longer available.'];
        }

        $connector = new WhmcsConnector($config + [
            'tls_mode' => $run->connection->tls_mode,
            'allow_insecure' => $run->connection->tls_mode === 'none',
        ]);
        $prefix = $config['prefix'];

        $pairs = [
            'clients' => ['clients', 'clients', null],
            'products' => ['products', 'products', null],
            'services' => ['hosting', 'services', 'amount'],
            'domains' => ['domains', 'domains', 'recurringamount'],
            'orders' => ['orders', 'orders', 'amount'],
            'invoices' => ['invoices', 'invoices', 'total'],
            'invoice_items' => ['invoiceitems', 'invoice_items', 'amount'],
            'transactions' => ['accounts', 'transactions', 'amountin'],
            'credits' => ['credit', 'credits', 'amount'],
            'tickets' => ['tickets', 'tickets', null],
            'ticket_replies' => ['ticketreplies', 'ticket_replies', null],
        ];

        $rows = [];
        foreach ($pairs as $entity => [$source, $target, $amountColumn]) {
            $sourceTable = $prefix.$source;
            $sourceCount = $this->safeCount(fn () => $connector->count($sourceTable));
            $targetCount = $this->safeCount(fn () => DB::table($target)->count());

            $sourceSum = $amountColumn ? $this->safeSum(fn () => $this->sourceSum($connector, $sourceTable, $amountColumn)) : null;
            $targetSum = $amountColumn ? $this->safeSum(fn () => (float) DB::table($target)->sum($this->targetAmountColumn($entity, $amountColumn))) : null;

            $rows[] = [
                'entity' => $entity,
                'source_count' => $sourceCount,
                'target_count' => $targetCount,
                'count_difference' => $targetCount - $sourceCount,
                'source_total' => $sourceSum,
                'target_total' => $targetSum,
                'total_difference' => $sourceSum !== null && $targetSum !== null
                    ? round($targetSum - $sourceSum, 2)
                    : null,
                'note' => $sourceSum !== null && $targetSum !== null && abs($targetSum - $sourceSum) > 0.01
                    ? 'Source and destination totals differ. Review currencies, refunds and credit treatment before enabling billing.'
                    : null,
            ];
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'dry_run' => $run->dry_run,
            'rows' => $rows,
            'financial_note' => 'Totals compare all records in PNLCS, including pre-existing data. Use the ID mapping report to isolate migrated records.',
        ];
    }

    protected function sourceSum(WhmcsConnector $connector, string $table, string $column): float
    {
        // information_schema confirms the column before it is used in SQL.
        $columns = array_column($connector->columns($table), 'name');
        if (! in_array($column, $columns, true)) {
            return 0.0;
        }

        return $connector->sum($table, $column);
    }

    protected function targetAmountColumn(string $entity, string $sourceColumn): string
    {
        return match ($entity) {
            'services' => 'amount',
            'transactions' => 'amount_in',
            default => $sourceColumn,
        };
    }

    protected function safeCount(callable $fn): int
    {
        try {
            return (int) $fn();
        } catch (Throwable) {
            return 0;
        }
    }

    protected function safeSum(callable $fn): ?float
    {
        try {
            return round((float) $fn(), 2);
        } catch (Throwable) {
            return null;
        }
    }
}
