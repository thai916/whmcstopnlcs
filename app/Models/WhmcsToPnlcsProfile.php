<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhmcsToPnlcsProfile extends Model
{
    protected $table = 'whmcs_to_pnlcs_profiles';

    protected $fillable = [
        'connection_id', 'name', 'scope', 'options', 'field_mapping',
    ];

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'options' => 'array',
            'field_mapping' => 'array',
        ];
    }

    public function connection()
    {
        return $this->belongsTo(WhmcsToPnlcsConnection::class, 'connection_id');
    }
}
