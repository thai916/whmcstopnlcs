<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhmcsToPnlcsLog extends Model
{
    protected $table = 'whmcs_to_pnlcs_logs';

    protected $fillable = [
        'run_id', 'entity', 'source_table', 'total', 'added', 'updated', 'skipped',
        'errors', 'error_details', 'skipped_details', 'admin_id',
    ];

    protected function casts(): array
    {
        return [
            'error_details' => 'array',
            'skipped_details' => 'array',
            'total' => 'integer',
            'added' => 'integer',
            'updated' => 'integer',
            'skipped' => 'integer',
            'errors' => 'integer',
        ];
    }
}
