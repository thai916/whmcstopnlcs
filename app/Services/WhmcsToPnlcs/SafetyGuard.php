<?php

namespace App\Services\WhmcsToPnlcs;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Preflight checks for automation side effects. Imported rows are written with
 * the query builder (no model events), but an administrator must still see the
 * live integrations configured in PNLCS before a migration is allowed.
 */
class SafetyGuard
{
    /** @return array<int, array<string, string>> */
    public function check(): array
    {
        $findings = [];

        $activeServers = $this->count('servers', fn ($q) => $q->where('active', 1)->where('disabled', 0));
        if ($activeServers > 0) {
            $findings[] = [
                'level' => 'warning',
                'title' => 'Live server integrations are configured',
                'detail' => $activeServers.' active server(s). Import creates service metadata only and never calls a provisioner. Confirm imported servers stay disabled until reviewed.',
            ];
        }

        $credentials = $this->count('servers', fn ($q) => $q->whereNotNull('password')->where('password', '!=', ''));
        if ($credentials > 0) {
            $findings[] = [
                'level' => 'info',
                'title' => 'Stored server credentials exist',
                'detail' => 'The migrator never copies source API passwords or tokens into active integrations.',
            ];
        }

        $gateways = $this->count('gateway_settings', fn ($q) => $q->where('setting', 'like', '%password%')->orWhere('setting', 'like', '%secret%'));
        if ($gateways > 0) {
            $findings[] = [
                'level' => 'warning',
                'title' => 'Payment gateway integrations are configured',
                'detail' => 'Historical payments are recorded as data only. No gateway is contacted and no refund is issued.',
            ];
        }

        $registrars = $this->count('registrar_settings', fn ($q) => $q->whereNotNull('value')->where('value', '!=', ''));
        if ($registrars > 0) {
            $findings[] = [
                'level' => 'warning',
                'title' => 'Registrar integrations are configured',
                'detail' => 'Domain registrations, renewals and transfers are never initiated by the migration.',
            ];
        }

        $cron = $this->setting('cron_enabled');
        if ($cron && ! in_array(strtolower((string) $cron), ['0', 'false', 'no', 'off'], true)) {
            $findings[] = [
                'level' => 'warning',
                'title' => 'Cron/automation may be active',
                'detail' => 'Disabling cron alone is not sufficient isolation. The migrator bypasses model events and business services; review automation after import before enabling imported services.',
            ];
        }

        $findings[] = [
            'level' => 'info',
            'title' => 'Migration write path is isolated',
            'detail' => 'Rows are inserted through the query builder. No emails, invoices, provisioning, suspension, termination, registrar or gateway operations are triggered.',
        ];

        return $findings;
    }

    protected function count(string $table, callable $filter): int
    {
        try {
            return $filter(DB::table($table))->count();
        } catch (Throwable) {
            return 0;
        }
    }

    protected function setting(string $key): ?string
    {
        try {
            $value = DB::table('settings')->where('setting', $key)->value('value');

            return $value === false || $value === null ? null : (string) $value;
        } catch (Throwable) {
            return null;
        }
    }

    /** Human-readable checklist rendered after a run. */
    public static function checklist(): array
    {
        return [
            'Review imported products before making them orderable.',
            'Review imported servers before enabling their API credentials.',
            'Verify invoice and payment totals against the source.',
            'Ask customers to reset passwords; no source password hashes are imported.',
            'Confirm cron and notification rules only after the verification step.',
        ];
    }
}
