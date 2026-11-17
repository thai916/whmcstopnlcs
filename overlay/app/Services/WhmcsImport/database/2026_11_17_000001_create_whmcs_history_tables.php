<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Pending integration into database/migrations once that directory is writable.
return new class extends Migration
{
    public function up(): void
    {
        self::create(Schema::getFacadeRoot());
    }

    public static function create(object $schema): void
    {
        $schema->create('whmcs_history_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source_key', 64);
            $table->string('snapshot_key', 64);
            $table->string('state')->default('pending');
            $table->json('categories');
            $table->json('checkpoints');
            $table->string('lease_token', 64)->nullable();
            $table->dateTime('lease_until')->nullable();
            $table->timestamps();
        });
        $schema->create('whmcs_history_records', function (Blueprint $table) {
            $table->id();
            $table->string('source_key', 64);
            $table->string('source_table', 128);
            $table->string('source_id', 191);
            $table->foreignId('run_id')->constrained('whmcs_history_runs');
            $table->string('checksum', 64);
            $table->string('disposition');
            $table->unsignedBigInteger('client_source_id')->nullable()->index();
            $table->longText('payload');
            $table->json('relationships');
            $table->timestamps();
            $table->unique(['source_key', 'source_table', 'source_id'], 'whmcs_history_identity');
        });
        $schema->create('whmcs_history_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('whmcs_history_runs');
            $table->string('source_table', 128);
            $table->string('source_id', 191);
            $table->string('code');
            $table->timestamps();
            $table->unique(['run_id', 'source_table', 'source_id'], 'whmcs_history_issue_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whmcs_history_issues');
        Schema::dropIfExists('whmcs_history_records');
        Schema::dropIfExists('whmcs_history_runs');
    }
};
