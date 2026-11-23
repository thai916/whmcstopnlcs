<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stable source identity for migrated transactions.
 *
 * WHMCS transaction IDs are frequently blank or reused for several distinct
 * payments. Keying only on transid collapsed separate financial history onto
 * one PNLCS row; the original source row ID keeps each payment distinct.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('transactions') && ! Schema::hasColumn('transactions', 'whmcs_transaction_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->unsignedBigInteger('whmcs_transaction_id')->nullable()->after('transaction_id');
                $table->index('whmcs_transaction_id', 'transactions_whmcs_transaction_id_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'whmcs_transaction_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropIndex('transactions_whmcs_transaction_id_index');
                $table->dropColumn('whmcs_transaction_id');
            });
        }
    }
};
