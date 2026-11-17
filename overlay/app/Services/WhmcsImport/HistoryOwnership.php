<?php

namespace App\Services\WhmcsImport;

use Illuminate\Database\Connection;

/**
 * Rebuildable access index, not native ownership or permission translation.
 * Conversation children belong to their ticket, never to the reply's author.
 * Ambiguous or mismatched ownership is withheld from customer-profile access.
 */
final class HistoryOwnership
{
    private const DIRECT = [
        'tblclients' => 'id', 'tblcontacts' => 'userid', 'tblusers_clients' => 'client_id',
        'tblorders' => 'userid', 'tblinvoices' => 'userid', 'tblaccounts' => 'userid',
        'tblcredit' => 'clientid', 'tblhosting' => 'userid', 'tblhostingaddons' => 'userid',
        'tbldomains' => 'userid', 'tbltickets' => 'userid', 'tblemails' => 'userid',
        'tblnotes' => 'userid', 'tblclientsfiles' => 'userid', 'tblmarketing_consent' => 'userid',
        'tblquotes' => 'userid', 'tblbillableitems' => 'userid',
    ];

    private const INHERITED = [
        'tblinvoiceitems' => ['invoiceid', 'tblinvoices'],
        'tblticketreplies' => ['tid', 'tbltickets'],
        'tblticketnotes' => ['ticketid', 'tbltickets'],
        'tblticketlog' => ['tid', 'tbltickets'],
        'tblcancelrequests' => ['relid', 'tblhosting'],
        'tblhostingconfigoptions' => ['relid', 'tblhosting'],
        'tblquoteitems' => ['quoteid', 'tblquotes'],
    ];

    public function __construct(private Connection $db, private HistoryStore $store)
    {
    }

    public function rebuild(string $source): array
    {
        $this->store->assertIsolated();

        return $this->db->transaction(function () use ($source) {
            $this->db->table('whmcs_history_owners')->where('source_key', $source)->delete();
            $report = ['indexed' => 0, 'withheld' => 0];
            $records = fn () => $this->db->table('whmcs_history_records')->where('source_key', $source);
            $records()->orderBy('id')->chunkById(100, function ($rows) use ($source, &$report) {
                foreach ($rows as $record) {
                    if (! isset(self::DIRECT[$record->source_table])) {
                        continue;
                    }
                    $row = $this->payload($record);
                    $client = (string) ($row[self::DIRECT[$record->source_table]] ?? '');
                    if (! $this->validClient($source, $client)) {
                        $report['withheld']++;

                        continue;
                    }
                    // A transaction attached to another customer's invoice is
                    // evidence of a conflict, not permission to disclose it.
                    if ($record->source_table === 'tblaccounts' && ! empty($row['invoiceid'])) {
                        $invoice = $this->store->find($source, 'tblinvoices', (string) $row['invoiceid']);
                        if (! $invoice || (string) ($this->payload($invoice)['userid'] ?? '') !== $client) {
                            $report['withheld']++;

                            continue;
                        }
                    }
                    $this->add($record, $client);
                    $report['indexed']++;
                }
            });
            // All authoritative parents above are indexed before any children,
            // regardless of category order or a previous partial run.
            $records()->whereIn('source_table', array_keys(self::INHERITED))->orderBy('id')
                ->chunkById(100, function ($rows) use ($source, &$report) {
                    foreach ($rows as $record) {
                        [$field, $table] = self::INHERITED[$record->source_table];
                        $row = $this->payload($record);
                        $parent = $this->store->find($source, $table, (string) ($row[$field] ?? ''));
                        $owners = $parent ? $this->db->table('whmcs_history_owners')
                            ->where('record_id', $parent->id)->pluck('client_source_id')->all() : [];
                        if (count($owners) !== 1 || (! empty($row['userid']) && (string) $row['userid'] !== (string) $owners[0])) {
                            $report['withheld']++;

                            continue;
                        }
                        $this->add($record, (string) $owners[0]);
                        $report['indexed']++;
                    }
                });
            // A WHMCS login may belong to multiple accounts. Never collapse
            // that many-to-many relationship into client_source_id.
            $records()->where('source_table', 'tblusers_clients')->orderBy('id')
                ->chunkById(100, function ($rows) use ($source, &$report) {
                    foreach ($rows as $link) {
                        $row = $this->payload($link);
                        $user = $this->store->find($source, 'tblusers', (string) ($row['auth_user_id'] ?? ''));
                        if ($user && $this->db->table('whmcs_history_owners')->where('record_id', $link->id)->exists()) {
                            $report['indexed'] += $this->add($user, (string) $row['client_id']);
                        }
                    }
                });

            return $report;
        });
    }

    private function payload(object $record): array
    {
        $json = $this->store->decode($record);
        if (! hash_equals($record->checksum, hash('sha256', $json))) {
            throw new \RuntimeException('Historical archive checksum mismatch.');
        }

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    private function validClient(string $source, string $client): bool
    {
        return ctype_digit($client) && $client !== '0' && $this->store->find($source, 'tblclients', $client) !== null;
    }

    private function add(object $record, string $client): int
    {
        return $this->db->table('whmcs_history_owners')->insertOrIgnore([
            'record_id' => $record->id, 'source_key' => $record->source_key, 'client_source_id' => $client,
        ]);
    }
}
