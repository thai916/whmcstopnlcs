<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhmcsToPnlcsReport extends Model
{
    protected $table = 'whmcs_to_pnlcs_reports';

    protected $fillable = ['run_id', 'type', 'format', 'filename', 'data', 'admin_id'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function run()
    {
        return $this->belongsTo(WhmcsToPnlcsRun::class, 'run_id');
    }
}
