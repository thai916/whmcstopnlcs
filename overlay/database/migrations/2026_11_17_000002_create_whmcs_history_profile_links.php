<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        self::create(Schema::getFacadeRoot());
    }

    public static function create(object $schema): void
    {
        $schema->table('whmcs_history_runs', function (Blueprint $table) {
            $table->longText('report')->nullable();
        });
        $schema->create('whmcs_history_client_links', function (Blueprint $table) {
            $table->id();
            $table->string('source_key', 64);
            $table->string('client_source_id', 191);
            // No cascade: deleting an operational customer must not erase history.
            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedBigInteger('admin_id');
            $table->timestamps();
            $table->unique(['source_key', 'client_source_id'], 'whmcs_history_client_identity');
        });
        $schema->create('whmcs_history_owners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained('whmcs_history_records');
            $table->string('source_key', 64);
            $table->string('client_source_id', 191);
            $table->unique(['record_id', 'client_source_id'], 'whmcs_history_owner_identity');
            $table->index(['source_key', 'client_source_id'], 'whmcs_history_owner_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whmcs_history_owners');
        Schema::dropIfExists('whmcs_history_client_links');
        Schema::table('whmcs_history_runs', fn (Blueprint $table) => $table->dropColumn('report'));
    }
};
