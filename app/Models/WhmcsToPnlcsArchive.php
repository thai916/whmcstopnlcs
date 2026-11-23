<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only historical storage for WHMCS records PNLCS cannot represent
 * natively. Never used by billing, automation or any operational query.
 */
class WhmcsToPnlcsArchive extends Model
{
    protected $table = 'whmcs_to_pnlcs_archive';

    protected $fillable = [
        'source_fingerprint', 'entity', 'source_id', 'data', 'source_created_at', 'run_id',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'source_created_at' => 'datetime',
        ];
    }
}
