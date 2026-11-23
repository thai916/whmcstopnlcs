<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhmcsToPnlcsRun extends Model
{
    protected $table = 'whmcs_to_pnlcs_runs';

    protected $fillable = [
        'connection_id', 'name', 'scope', 'options', 'dry_run', 'status',
        'current_entity', 'entities', 'entity_index', 'offset', 'batch_size',
        'totals', 'processed', 'counts', 'source_fingerprint', 'source_info',
        'timezone', 'safety', 'warnings', 'started_at', 'finished_at', 'admin_id',
    ];

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'options' => 'array',
            'dry_run' => 'boolean',
            'entities' => 'array',
            'entity_index' => 'integer',
            'offset' => 'integer',
            'batch_size' => 'integer',
            'totals' => 'array',
            'processed' => 'array',
            'counts' => 'array',
            'source_info' => 'array',
            'timezone' => 'array',
            'safety' => 'array',
            'warnings' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function connection()
    {
        return $this->belongsTo(WhmcsToPnlcsConnection::class, 'connection_id');
    }

    public function records()
    {
        return $this->hasMany(WhmcsToPnlcsRecord::class, 'run_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'running', 'paused'], true);
    }
}
