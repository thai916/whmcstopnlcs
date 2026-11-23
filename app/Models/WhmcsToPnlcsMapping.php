<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhmcsToPnlcsMapping extends Model
{
    protected $table = 'whmcs_to_pnlcs_mappings';

    protected $fillable = [
        'source_fingerprint', 'entity', 'source_id', 'target_id', 'target_table',
        'payload_hash', 'status',
    ];

    /** Look up a previously imported target id for a source entity. */
    public static function targetFor(string $fingerprint, string $entity, string|int $sourceId): ?int
    {
        $row = static::where('source_fingerprint', $fingerprint)
            ->where('entity', $entity)
            ->where('source_id', (string) $sourceId)
            ->first();

        return $row && $row->target_id !== null ? (int) $row->target_id : null;
    }
}
