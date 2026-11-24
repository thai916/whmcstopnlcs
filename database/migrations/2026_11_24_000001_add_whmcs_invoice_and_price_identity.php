<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stable invoice identity and price provenance for the WHMCS migration.
 *
 * WHMCS often leaves invoicenum blank and shows the row ID instead. Without
 * source_invoice_id as the import key, distinct invoices collapse. Product and
 * service prices are converted for display in the PNLCS shop currency, so the
 * original values and their source currency must remain auditable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices')
            && Schema::hasColumn('invoices', 'source_invoice_id')
            && ! $this->hasIndex('invoices', 'invoices_source_invoice_id_index')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->index('source_invoice_id', 'invoices_source_invoice_id_index');
            });
        }

        if (Schema::hasTable('pricing')) {
            Schema::table('pricing', function (Blueprint $table) {
                if (! Schema::hasColumn('pricing', 'whmcs_source_currency_id')) {
                    $table->unsignedBigInteger('whmcs_source_currency_id')->nullable()->after('rel_id');
                }
                if (! Schema::hasColumn('pricing', 'whmcs_converted_from_id')) {
                    $table->unsignedBigInteger('whmcs_converted_from_id')->nullable()->after('whmcs_source_currency_id');
                }
            });
        }

        if (Schema::hasTable('services')) {
            Schema::table('services', function (Blueprint $table) {
                if (! Schema::hasColumn('services', 'whmcs_currency_id')) {
                    $table->unsignedBigInteger('whmcs_currency_id')->nullable()->after('whmcs_service_id');
                }
                if (! Schema::hasColumn('services', 'whmcs_original_amount')) {
                    $table->decimal('whmcs_original_amount', 15, 2)->nullable()->after('amount');
                }
                if (! Schema::hasColumn('services', 'whmcs_original_first_payment_amount')) {
                    $table->decimal('whmcs_original_first_payment_amount', 15, 2)->nullable()->after('first_payment_amount');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('services')) {
            Schema::table('services', function (Blueprint $table) {
                foreach (['whmcs_currency_id', 'whmcs_original_amount', 'whmcs_original_first_payment_amount'] as $column) {
                    if (Schema::hasColumn('services', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('pricing')) {
            Schema::table('pricing', function (Blueprint $table) {
                foreach (['whmcs_source_currency_id', 'whmcs_converted_from_id'] as $column) {
                    if (Schema::hasColumn('pricing', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('invoices') && $this->hasIndex('invoices', 'invoices_source_invoice_id_index')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropIndex('invoices_source_invoice_id_index');
            });
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(DB::select('SHOW INDEX FROM `'.$table.'`'))
            ->contains(fn ($row) => $row->Key_name === $index);
    }
};
