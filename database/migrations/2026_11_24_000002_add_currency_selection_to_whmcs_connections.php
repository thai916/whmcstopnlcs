<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional explicit currency pair for WHMCS -> PNLCS price conversion.
 *
 * WHMCS currency IDs are not PNLCS currency IDs. Storing both selections on
 * the connection lets an administrator choose the source currency and the
 * PNLCS currency that converted prices should be written to, while the existing
 * per-row mapping is still used to resolve rates.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('whmcs_to_pnlcs_connections')) {
            return;
        }

        Schema::table('whmcs_to_pnlcs_connections', function (Blueprint $table) {
            if (! Schema::hasColumn('whmcs_to_pnlcs_connections', 'whmcs_currency_id')) {
                $table->unsignedBigInteger('whmcs_currency_id')->nullable()->after('prefix');
            }
            if (! Schema::hasColumn('whmcs_to_pnlcs_connections', 'pnlcs_currency_id')) {
                $table->unsignedBigInteger('pnlcs_currency_id')->nullable()->after('whmcs_currency_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('whmcs_to_pnlcs_connections')) {
            return;
        }

        Schema::table('whmcs_to_pnlcs_connections', function (Blueprint $table) {
            foreach (['whmcs_currency_id', 'pnlcs_currency_id'] as $column) {
                if (Schema::hasColumn('whmcs_to_pnlcs_connections', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
