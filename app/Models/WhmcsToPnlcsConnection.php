<?php

namespace App\Models;

use App\Casts\EncryptedValue;
use Illuminate\Database\Eloquent\Model;

class WhmcsToPnlcsConnection extends Model
{
    protected $table = 'whmcs_to_pnlcs_connections';

    protected $fillable = [
        'name', 'host', 'port', 'database', 'username', 'password', 'prefix',
        'tls_mode', 'timeout', 'ca_path', 'tls_hostname',
        'last_tls_active', 'last_identity_verified', 'last_tested_at', 'last_diagnostics',
    ];

    protected function casts(): array
    {
        return [
            'password' => EncryptedValue::class,
            'port' => 'integer',
            'timeout' => 'integer',
            'last_tls_active' => 'boolean',
            'last_identity_verified' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_diagnostics' => 'array',
        ];
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return [
            'host' => (string) $this->host,
            'port' => (int) ($this->port ?: 3306),
            'database' => (string) $this->database,
            'username' => (string) $this->username,
            'password' => (string) ($this->password ?? ''),
            'prefix' => (string) ($this->prefix ?: 'tbl'),
            'tls_mode' => (string) ($this->tls_mode ?: 'verified'),
            'timeout' => (int) ($this->timeout ?: 10),
            'ca_path' => $this->ca_path,
            'tls_hostname' => $this->tls_hostname,
        ];
    }

    /** Never let a credential reach a log, view or export. */
    public function safeAttributes(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'prefix' => $this->prefix,
            'tls_mode' => $this->tls_mode,
            'timeout' => $this->timeout,
        ];
    }
}
