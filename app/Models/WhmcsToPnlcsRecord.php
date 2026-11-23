<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhmcsToPnlcsRecord extends Model
{
    protected $table = 'whmcs_to_pnlcs_records';

    protected $fillable = [
        'run_id', 'entity', 'source_id', 'target_id', 'status', 'error',
        'retry_count', 'payload_hash',
    ];

    public function run()
    {
        return $this->belongsTo(WhmcsToPnlcsRun::class, 'run_id');
    }
}
