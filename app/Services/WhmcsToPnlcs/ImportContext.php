<?php

namespace App\Services\WhmcsToPnlcs;

use App\Models\WhmcsToPnlcsArchive;
use App\Models\WhmcsToPnlcsMapping;
use App\Models\WhmcsToPnlcsRun;

/**
 * State shared by every entity importer in one run: source identity, id
 * mappings, dry-run flag and the transforms that keep historical values honest.
 */
class ImportContext
{
    /** @var list<string> */
    public array $warnings = [];

    public function __construct(
        public WhmcsToPnlcsRun $run,
        public WhmcsConnector $connector,
        public string $fingerprint,
        public array $options = [],
    ) {}

    public function warn(string $message): void
    {
        if (! in_array($message, $this->warnings, true)) {
            $this->warnings[] = $message;
        }
    }

    public function dryRun(): bool
    {
        return (bool) $this->run->dry_run;
    }

    public function sourceId(mixed $value): string
    {
        return (string) ($value ?? '');
    }

    public function targetId(string $entity, mixed $sourceId): ?int
    {
        if ($sourceId === null || $sourceId === '') {
            return null;
        }

        return WhmcsToPnlcsMapping::targetFor($this->fingerprint, $entity, $this->sourceId($sourceId));
    }

    public function remember(string $entity, mixed $sourceId, ?int $targetId, string $table, ?string $hash = null): void
    {
        if ($this->dryRun() || $targetId === null) {
            return;
        }

        WhmcsToPnlcsMapping::updateOrCreate([
            'source_fingerprint' => $this->fingerprint,
            'entity' => $entity,
            'source_id' => $this->sourceId($sourceId),
        ], [
            'target_id' => $targetId,
            'target_table' => $table,
            'payload_hash' => $hash,
            'status' => 'imported',
        ]);
    }

    public function archive(string $entity, mixed $sourceId, array $data, mixed $createdAt = null): void
    {
        if ($this->dryRun()) {
            return;
        }

        WhmcsToPnlcsArchive::updateOrCreate([
            'source_fingerprint' => $this->fingerprint,
            'entity' => $entity,
            'source_id' => $this->sourceId($sourceId),
        ], [
            'data' => $this->redact($data),
            'source_created_at' => $this->dateOrNull($createdAt),
            'run_id' => $this->run->id,
        ]);
    }

    /** Deterministic UUID: a re-import of the same source row is the same client. */
    public function uuid(string $entity, mixed $sourceId): string
    {
        $seed = $this->fingerprint.':'.$entity.':'.$this->sourceId($sourceId);
        $hash = hash('sha256', $seed);

        // RFC 4122 v4-shaped deterministic UUID (version/variant bits forced).
        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-4'.substr($hash, 13, 3)
            .'-'.dechex((hexdec($hash[16]) & 0x3) | 0x8).substr($hash, 17, 3)
            .'-'.substr($hash, 20, 12);
    }

    /** Historical status words mapped to native PNLCS values. */
    public function transform(string $name, mixed $value): mixed
    {
        $value = is_string($value) ? trim($value) : $value;

        return match ($name) {
            'clientStatus' => match (strtolower((string) $value)) {
                'active' => 'active',
                'inactive', 'closed' => 'inactive',
                default => 'active',
            },
            'serviceStatus' => match (strtolower((string) $value)) {
                'active' => 'active',
                'pending' => 'pending',
                'suspended', 'suspended by admin' => 'suspended',
                'cancelled' => 'cancelled',
                'terminated' => 'terminated',
                'fraud' => 'fraud',
                default => strtolower((string) $value) ?: 'pending',
            },
            'domainStatus' => match (strtolower((string) $value)) {
                'active' => 'active',
                'pending', 'pending registration', 'pending transfer' => 'pending',
                'expired' => 'expired',
                'cancelled' => 'cancelled',
                'fraud' => 'fraud',
                default => strtolower((string) $value) ?: 'pending',
            },
            'billingCycle' => match (strtolower((string) $value)) {
                'monthly' => 'monthly',
                'quarterly' => 'quarterly',
                'semiannually', 'semi-annually', 'half yearly' => 'semiannually',
                'annually', 'annual', 'yearly' => 'annually',
                'biennially', 'biennial', 'every 2 years' => 'biennially',
                'triennially', 'triennial', 'every 3 years' => 'triennially',
                'one time', 'onetime', 'one-time' => 'one_time',
                default => strtolower((string) $value) ?: 'monthly',
            },
            'ticketPriority' => match (strtolower((string) $value)) {
                'high', 'urgent' => 'high',
                'medium', 'normal' => 'medium',
                'low' => 'low',
                default => strtolower((string) $value) ?: 'medium',
            },
            // WHMCS stores several flag columns as text ("on" or blank).
            'yesNoFlag' => in_array(strtolower((string) $value), ['1', 'on', 'yes', 'true'], true) ? 1 : 0,
            default => $value,
        };
    }

    /** Zero dates are "unknown", not a date in year zero. */
    public function dateOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '' || str_starts_with($value, '0000-00-00') || str_starts_with($value, '-0001-')) {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? substr($value, 0, 19) : null;
    }

    /** Never persist credentials, card data or payment tokens in the archive. */
    protected function redact(array $data): array
    {
        $blocked = ['password', 'pass', 'accesshash', 'access_hash', 'apikey', 'api_key',
            'secret', 'cardnum', 'cardnumber', 'cvv', 'cvc', 'token', 'remote_token',
            'eppcode', 'epp_code', 'authcode', 'auth_code'];

        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), $blocked, true)) {
                $data[$key] = '[redacted]';
            }
        }

        return $data;
    }
}
