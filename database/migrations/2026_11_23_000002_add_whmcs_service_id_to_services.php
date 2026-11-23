<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stable source identity for migrated hosting services.
 *
 * The original utility deduplicated services on client/domain/product name,
 * which collapsed several distinct WHMCS services onto one PNLCS row. The
 * persistent mapping table plus this column keep each source service distinct
 * and make the original ID auditable in the destination.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('services') && ! Schema::hasColumn('services', 'whmcs_service_id')) {
            Schema::table('services', function (Blueprint $table) {
                $table->unsignedBigInteger('whmcs_service_id')->nullable()->after('whmcs_product_name');
                $table->index('whmcs_service_id', 'services_whmcs_service_id_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('services') && Schema::hasColumn('services', 'whmcs_service_id')) {
            Schema::table('services', function (Blueprint $table) {
                $table->dropIndex('services_whmcs_service_id_index');
                $table->dropColumn('whmcs_service_id');
            });
        }
    }
};
