<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHMCS source fields can be longer than the original PNLCS columns. Widen
 * them so the imported history is preserved natively rather than truncated.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('clients') && Schema::hasColumn('clients', 'phone_number')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->string('phone_number', 255)->nullable()->change();
            });
        }
        if (Schema::hasTable('currencies') && Schema::hasColumn('currencies', 'code')) {
            Schema::table('currencies', function (Blueprint $table) {
                $table->string('code', 20)->change();
            });
        }
    }

    public function down(): void
    {
        // Irreversible: narrowing could discard imported source values.
    }
};
