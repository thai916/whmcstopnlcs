<?php

namespace App\Services\WhmcsImport;

/**
 * Exact read-only evidence, intentionally not PaymentService::balance().
 * Raw WHMCS credit/refund fields are never promoted to verified allocations.
 */
final class HistoryFinance
{
    public function __construct(private HistoryStore $store)
    {}

    public function invoice(int $client, object $invoice): array
    {
        if ($invoice->source_table !== 'tblinvoices'
            || ! $this->store->profileRecords($client)->where('id', $invoice->id)->exists()) {
            throw new \RuntimeException('Invoice is outside the mapped customer archive.');
        }
        $original = $this->payload($invoice);
        $report = [
            'source_invoice_id' => $invoice->source_id,
            'original_invoice_number' => $original['invoicenum'] ?? null,
            'original_total' => $original['total'] ?? null,
            'original_credit' => $original['credit'] ?? null,
            'invoice_currency' => 'unknown_not_in_invoice_row',
            'item_count' => 0, 'item_amount_sum' => '0.00',
            'transactions_by_explicit_currency' => [],
            'related_records' => [],
            'limitations' => [
                'No outstanding balance or native financial compatibility is asserted.',
                'Client current currency does not establish historical invoice currency.',
                'refundid and credit.relid are retained evidence, not verified allocations.',
                'Fees are reported separately; they do not reduce the observed customer inflow.',
                'Related links are capped at 50; counts and sums include all accessible matching records.',
            ],
        ];
        $this->store->profileRecords($client)->where('source_key', $invoice->source_key)
            ->whereIn('source_table', ['tblinvoiceitems', 'tblaccounts'])
            ->where('relationships->invoiceid->id', (string) $invoice->source_id)
            ->orderBy('id')->chunkById(100, function ($records) use (&$report) {
                foreach ($records as $record) {
                    $row = $this->payload($record);
                    if (count($report['related_records']) < 50) {
                        $report['related_records'][] = [
                            'archive_id' => $record->id, 'table' => $record->source_table,
                            'source_id' => $record->source_id, 'date' => $row['date'] ?? null,
                            'transaction_reference' => $row['transid'] ?? null,
                            'raw_refundid_unverified' => $row['refundid'] ?? null,
                        ];
                    }
                    if ($record->source_table === 'tblinvoiceitems') {
                        $report['item_count']++;
                        $report['item_amount_sum'] = ExactAmount::add($report['item_amount_sum'], $this->money($row, 'amount'));

                        continue;
                    }
                    $currency = (string) ($row['currency'] ?? 'unknown');
                    $group = &$report['transactions_by_explicit_currency'][$currency];
                    $group ??= ['count' => 0, 'amountin' => '0.00', 'amountout' => '0.00', 'fees' => '0.00', 'net_observed_flow' => '0.00'];
                    $group['count']++;
                    foreach (['amountin', 'amountout', 'fees'] as $field) {
                        $group[$field] = ExactAmount::add($group[$field], $this->money($row, $field));
                    }
                    $out = ExactAmount::normalize($group['amountout']);
                    $negativeOut = str_starts_with($out, '-') ? substr($out, 1) : '-'.$out;
                    $group['net_observed_flow'] = ExactAmount::add($group['amountin'], $negativeOut);
                    unset($group);
                }
            });

        return $report;
    }

    private function payload(object $record): array
    {
        $json = $this->store->decode($record);
        if (! hash_equals($record->checksum, hash('sha256', $json))) {
            throw new \RuntimeException('Historical archive checksum mismatch.');
        }

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    private function money(array $row, string $field): string
    {
        if (! array_key_exists($field, $row) || $row[$field] === null || is_float($row[$field])) {
            throw new \RuntimeException('Missing or lossy financial evidence.');
        }

        return ExactAmount::normalize((string) $row[$field]);
    }
}
