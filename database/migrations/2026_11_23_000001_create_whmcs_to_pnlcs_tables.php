<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storage for the independent "WHMCS to PNLCS" migration utility.
 *
 * These tables are deliberately separate from the original Import WHMCS
 * utility's tables. Nothing here is read or written by the legacy importer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('whmcs_to_pnlcs_connections')) {
            Schema::create('whmcs_to_pnlcs_connections', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('host');
                $table->unsignedSmallInteger('port')->default(3306);
                $table->string('database');
                $table->string('username');
                $table->text('password')->nullable();
                $table->string('prefix', 64)->default('tbl');
                $table->string('tls_mode', 20)->default('verified'); // verified, unverified, none
                $table->unsignedSmallInteger('timeout')->default(10);
                $table->string('ca_path')->nullable();
                $table->string('tls_hostname')->nullable();
                $table->boolean('last_tls_active')->default(false);
                $table->boolean('last_identity_verified')->default(false);
                $table->timestamp('last_tested_at')->nullable();
                $table->json('last_diagnostics')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('whmcs_to_pnlcs_profiles')) {
            Schema::create('whmcs_to_pnlcs_profiles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('connection_id')->constrained('whmcs_to_pnlcs_connections')->cascadeOnDelete();
                $table->string('name');
                $table->json('scope')->nullable();
                $table->json('options')->nullable();
                $table->json('field_mapping')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('whmcs_to_pnlcs_runs')) {
            Schema::create('whmcs_to_pnlcs_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('connection_id')->constrained('whmcs_to_pnlcs_connections')->cascadeOnDelete();
                $table->string('name')->nullable();
                $table->json('scope');
                $table->json('options')->nullable();
                $table->boolean('dry_run')->default(false);
                $table->string('status', 20)->default('pending'); // pending,running,paused,completed,cancelled,failed
                $table->string('current_entity')->nullable();
                $table->json('entities')->nullable();
                $table->unsignedInteger('entity_index')->default(0);
                $table->unsignedInteger('offset')->default(0);
                $table->unsignedInteger('batch_size')->default(100);
                $table->json('totals')->nullable();
                $table->json('processed')->nullable();
                $table->json('counts')->nullable();
                $table->string('source_fingerprint', 64)->nullable();
                $table->json('source_info')->nullable();
                $table->json('timezone')->nullable();
                $table->json('safety')->nullable();
                $table->json('warnings')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->foreignId('admin_id')->nullable();
                $table->timestamps();
                $table->index('status');
                $table->index('source_fingerprint');
            });
        }

        if (! Schema::hasTable('whmcs_to_pnlcs_records')) {
            Schema::create('whmcs_to_pnlcs_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('run_id')->constrained('whmcs_to_pnlcs_runs')->cascadeOnDelete();
                $table->string('entity', 64);
                $table->string('source_id', 64);
                $table->string('target_id', 64)->nullable();
                $table->string('status', 20)->default('pending');
                $table->text('error')->nullable();
                $table->unsignedSmallInteger('retry_count')->default(0);
                $table->string('payload_hash', 64)->nullable();
                $table->timestamps();
                $table->unique(['run_id', 'entity', 'source_id'], 'wtp_record_unique');
                $table->index(['entity', 'source_id']);
            });
        }

        if (! Schema::hasTable('whmcs_to_pnlcs_mappings')) {
            Schema::create('whmcs_to_pnlcs_mappings', function (Blueprint $table) {
                $table->id();
                $table->string('source_fingerprint', 64);
                $table->string('entity', 64);
                $table->string('source_id', 64);
                $table->string('target_id', 64)->nullable();
                $table->string('target_table', 64)->nullable();
                $table->string('payload_hash', 64)->nullable();
                $table->string('status', 20)->default('imported');
                $table->timestamps();
                $table->unique(['source_fingerprint', 'entity', 'source_id'], 'wtp_mapping_unique');
                $table->index(['source_fingerprint', 'entity']);
            });
        }

        if (! Schema::hasTable('whmcs_to_pnlcs_archive')) {
            Schema::create('whmcs_to_pnlcs_archive', function (Blueprint $table) {
                $table->id();
                $table->string('source_fingerprint', 64);
                $table->string('entity', 64);
                $table->string('source_id', 64);
                $table->json('data');
                $table->timestamp('source_created_at')->nullable();
                $table->foreignId('run_id')->nullable()->constrained('whmcs_to_pnlcs_runs')->nullOnDelete();
                $table->timestamps();
                $table->unique(['source_fingerprint', 'entity', 'source_id'], 'wtp_archive_unique');
            });
        }

        if (! Schema::hasTable('whmcs_to_pnlcs_reports')) {
            Schema::create('whmcs_to_pnlcs_reports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('run_id')->nullable()->constrained('whmcs_to_pnlcs_runs')->cascadeOnDelete();
                $table->string('type', 64);
                $table->string('format', 8)->default('csv');
                $table->string('filename');
                $table->json('data')->nullable();
                $table->foreignId('admin_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('whmcs_to_pnlcs_logs')) {
            Schema::create('whmcs_to_pnlcs_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('run_id')->nullable()->constrained('whmcs_to_pnlcs_runs')->cascadeOnDelete();
                $table->string('entity', 64)->nullable();
                $table->string('source_table')->nullable();
                $table->unsignedInteger('total')->default(0);
                $table->unsignedInteger('added')->default(0);
                $table->unsignedInteger('updated')->default(0);
                $table->unsignedInteger('skipped')->default(0);
                $table->unsignedInteger('errors')->default(0);
                $table->json('error_details')->nullable();
                $table->json('skipped_details')->nullable();
                $table->foreignId('admin_id')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'whmcs_to_pnlcs_logs',
            'whmcs_to_pnlcs_reports',
            'whmcs_to_pnlcs_archive',
            'whmcs_to_pnlcs_mappings',
            'whmcs_to_pnlcs_records',
            'whmcs_to_pnlcs_runs',
            'whmcs_to_pnlcs_profiles',
            'whmcs_to_pnlcs_connections',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
