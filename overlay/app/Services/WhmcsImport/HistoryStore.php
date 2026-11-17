<?php

namespace App\Services\WhmcsImport;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Application-owned historical archive, NOT operational billing records.
 * Uses query builder only: no Eloquent hooks, dispatches, mail or live services.
 */
final class HistoryStore
{
    public function __construct(
        private Connection $db,
        private Encrypter $encryption,
    ) {}

    public function assertIsolated(): void
    {
        // Deliberately no production override in this first increment.
        if (! in_array($this->db->getDatabaseName(), [':memory:'], true)
            && ! preg_match('/_whmcs_(test|staging)$/D', $this->db->getDatabaseName())) {
            throw new RuntimeException('Destination must be :memory: or end in _whmcs_test/_whmcs_staging.');
        }
        foreach (['whmcs_history_runs', 'whmcs_history_records', 'whmcs_history_issues'] as $table) {
            if (! $this->db->getSchemaBuilder()->hasTable($table)) {
                throw new RuntimeException('Historical tracking schema is not installed.');
            }
        }
    }

    public function start(string $source, string $snapshot, array $categories): int
    {
        $this->assertIsolated();
        foreach ([$source, $snapshot] as $identity) {
            if (! preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $identity)) {
                throw new RuntimeException('Use a stable, non-sensitive source/snapshot identifier.');
            }
        }

        return (int) $this->db->table('whmcs_history_runs')->insertGetId([
            'source_key' => $source, 'snapshot_key' => $snapshot, 'state' => 'pending',
            'categories' => json_encode($categories, JSON_THROW_ON_ERROR), 'checkpoints' => '{}',
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function claim(int $id, string $source, string $snapshot, array $categories): array
    {
        $this->assertIsolated();
        $token = bin2hex(random_bytes(24));
        $now = gmdate('Y-m-d H:i:s');
        $run = $this->db->table('whmcs_history_runs')->where('id', $id)->first();
        if (! $run || $run->source_key !== $source || $run->snapshot_key !== $snapshot
            || json_decode($run->categories, true, flags: JSON_THROW_ON_ERROR) !== $categories) {
            throw new RuntimeException('Resume identity/categories differ from the original run.');
        }
        $claimed = $this->db->table('whmcs_history_runs')->where('id', $id)
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', $now))
            ->update(['state' => 'running', 'lease_token' => $token, 'lease_until' => gmdate('Y-m-d H:i:s', time() + 300)]);
        if ($claimed !== 1) {
            throw new RuntimeException('Run is leased by another worker; retry after its lease expires.');
        }
        $checkpoints = json_decode($run->checkpoints, true, flags: JSON_THROW_ON_ERROR);
        // Failed categories are replayed idempotently so failures before a checkpoint
        // are not silently lost. The snapshot must remain immutable.
        foreach ($this->db->table('whmcs_history_issues')->where('run_id', $id)->pluck('source_table') as $table) {
            unset($checkpoints[$table]);
        }

        return [$token, $checkpoints];
    }

    public function find(string $source, string $table, string $id): ?object
    {
        return $this->db->table('whmcs_history_records')
            ->where('source_key', $source)->where('source_table', $table)->where('source_id', $id)->first();
    }

    public function save(int $run, string $token, string $source, string $table, array $row, array $references): string
    {
        return $this->db->transaction(function () use ($run, $token, $source, $table, $row, $references) {
            $this->assertLease($run, $token);
            $id = (string) $row['id'];
            ksort($row);
            $json = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $checksum = hash('sha256', $json);
            $existing = $this->find($source, $table, $id);
            if ($existing) {
                if ($existing->checksum !== $checksum || hash('sha256', $this->decode($existing)) !== $checksum) {
                    throw new RuntimeException('SOURCE_OR_ARCHIVE_CHANGED');
                }
                $outcome = 'unchanged';
            } else {
                $this->db->table('whmcs_history_records')->insert([
                    'source_key' => $source, 'source_table' => $table, 'source_id' => $id,
                    'run_id' => $run, 'checksum' => $checksum, 'disposition' => 'historical_read_only',
                    'client_source_id' => $this->clientSourceId($source, $table, $row, $references),
                    'payload' => $this->encryption->encryptString($json),
                    'relationships' => json_encode($references, JSON_THROW_ON_ERROR),
                    'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
                $outcome = 'archived';
            }
            $this->db->table('whmcs_history_issues')->where('run_id', $run)
                ->where('source_table', $table)->where('source_id', $id)->delete();

            return $outcome;
        });
    }

    public function issue(int $run, string $token, string $table, string $id, string $code): void
    {
        $this->assertLease($run, $token);
        $this->db->table('whmcs_history_issues')->updateOrInsert(
            ['run_id' => $run, 'source_table' => $table, 'source_id' => $id],
            ['code' => $code, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')],
        );
    }

    public function checkpoint(int $run, string $token, array $checkpoints): void
    {
        $this->assertLease($run, $token);
        $this->db->table('whmcs_history_runs')->where('id', $run)->where('lease_token', $token)->update([
            'checkpoints' => json_encode($checkpoints, JSON_THROW_ON_ERROR),
            'lease_until' => gmdate('Y-m-d H:i:s', time() + 300), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function finish(int $run, string $token, string $state, ?array $report = null): void
    {
        $this->assertLease($run, $token);
        $values = [
            'state' => $state, 'lease_token' => null, 'lease_until' => null, 'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($report !== null && $this->db->getSchemaBuilder()->hasColumn('whmcs_history_runs', 'report')) {
            $values['report'] = json_encode($report, JSON_THROW_ON_ERROR);
        }
        $this->db->table('whmcs_history_runs')->where('id', $run)->where('lease_token', $token)->update($values);
    }

    /** Decrypted history is for authorized administrators only, never logs. */
    public function decode(object $record): string
    {
        return $this->encryption->decryptString($record->payload);
    }

    public function clientHistory(string $source, string $clientId, int $after = 0, int $limit = 50): array
    {
        return $this->db->table('whmcs_history_records')->where('source_key', $source)
            ->where('client_source_id', $clientId)->where('id', '>', $after)->orderBy('id')
            ->limit(max(1, min(100, $limit)))->get()->all();
    }

    public function profileSchemaInstalled(): bool
    {
        foreach (['whmcs_history_records', 'whmcs_history_client_links', 'whmcs_history_owners'] as $table) {
            if (! $this->db->getSchemaBuilder()->hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /** Explicit, immutable adoption. Email matches alone never grant access. */
    public function linkClient(string $source, string $sourceClient, int $client, int $admin): void
    {
        $this->assertIsolated();
        if (! $this->profileSchemaInstalled() || ! $this->find($source, 'tblclients', $sourceClient)) {
            throw new RuntimeException('Archive a source client before linking it.');
        }
        $this->db->transaction(function () use ($source, $sourceClient, $client, $admin) {
            $this->db->table('whmcs_history_client_links')->insertOrIgnore([
                'source_key' => $source, 'client_source_id' => $sourceClient, 'client_id' => $client,
                'admin_id' => $admin, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $existing = $this->db->table('whmcs_history_client_links')
                ->where('source_key', $source)->where('client_source_id', $sourceClient)->first();
            if (! $existing || (int) $existing->client_id !== $client) {
                throw new RuntimeException('Source customer is already linked to a different PNLCS customer.');
            }
        });
    }

    /** All profile reads, including individual records, use this same scope. */
    public function profileRecords(int $client): \Illuminate\Database\Query\Builder
    {
        return $this->db->table('whmcs_history_records')->whereExists(function ($query) use ($client) {
            $query->selectRaw('1')->from('whmcs_history_owners as owners')
                ->join('whmcs_history_client_links as links', function ($join) {
                    $join->on('links.source_key', '=', 'owners.source_key')
                        ->on('links.client_source_id', '=', 'owners.client_source_id');
                })
                ->whereColumn('owners.record_id', 'whmcs_history_records.id')->where('links.client_id', $client);
        });
    }

    public function rebuildOwnership(string $source): array
    {
        $this->assertIsolated();

        return (new HistoryOwnership($this->db, $this))->rebuild($source);
    }

    /** Bounded archive audit. Expected links are not promoted to verified semantics. */
    public function reconcile(string $source, array $tables): array
    {
        $report = ['tables' => [], 'missing_expected_links' => 0, 'owner_conflicts' => 0, 'checksum_failures' => 0];
        $this->db->table('whmcs_history_records')->where('source_key', $source)
            ->whereIn('source_table', $tables)->orderBy('id')->chunkById(100, function ($records) use ($source, &$report) {
                foreach ($records as $record) {
                    $json = $this->decode($record);
                    if (hash('sha256', $json) !== $record->checksum) {
                        $report['checksum_failures']++;
                    }
                    $row = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                    $stats = &$report['tables'][$record->source_table];
                    $stats ??= ['count' => 0, 'totals' => []];
                    $stats['count']++;
                    $currency = (string) ($row['currency'] ?? 'unknown');
                    foreach (['amount', 'amountin', 'amountout', 'fees', 'credit', 'subtotal', 'tax', 'tax2', 'total', 'firstpaymentamount', 'recurringamount', 'setupfee', 'recurring'] as $field) {
                        if (isset($row[$field])) {
                            $stats['totals'][$currency][$field] = ExactAmount::add(
                                $stats['totals'][$currency][$field] ?? '0', (string) $row[$field]);
                        }
                    }
                    foreach (json_decode($record->relationships, true, flags: JSON_THROW_ON_ERROR) as $reference) {
                        $parent = $this->find($source, $reference['table'], $reference['id']);
                        if (! $parent) {
                            $report['missing_expected_links']++;
                        } elseif ($reference['table'] === 'tblinvoices'
                            && $record->client_source_id && $parent->client_source_id
                            && (string) $record->client_source_id !== (string) $parent->client_source_id) {
                            $report['owner_conflicts']++;
                        }
                    }
                    unset($stats);
                }
            });

        return $report;
    }

    private function assertLease(int $run, string $token): void
    {
        if (! $this->db->table('whmcs_history_runs')->where('id', $run)->where('lease_token', $token)
            ->where('lease_until', '>=', gmdate('Y-m-d H:i:s'))->exists()) {
            throw new RuntimeException('Historical run lease expired.');
        }
    }

    private function clientSourceId(string $source, string $table, array $row, array $references): ?string
    {
        $value = match ($table) {
            'tblclients' => $row['id'],
            'tblcredit' => $row['clientid'] ?? null,
            'tblusers_clients' => $row['client_id'] ?? null,
            default => $row['userid'] ?? null,
        };

        if ($value && ctype_digit((string) $value)) {
            return (string) $value;
        }
        foreach ($references as $reference) {
            $parent = $this->find($source, $reference['table'], $reference['id']);
            if ($parent && $parent->client_source_id !== null) {
                return (string) $parent->client_source_id;
            }
        }

        return null;
    }
}
