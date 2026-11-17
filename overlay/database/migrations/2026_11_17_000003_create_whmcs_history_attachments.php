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
        $schema->create('whmcs_history_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained('whmcs_history_records');
            $table->string('field', 64);
            $table->string('path_hash', 64);
            $table->text('source_path');
            $table->string('reference_hash', 64);
            $table->string('sha256', 64);
            $table->unsignedBigInteger('bytes');
            $table->string('download_name');
            $table->string('state')->default('planned');
            $table->timestamps();
            $table->unique(['record_id', 'field', 'path_hash'], 'whmcs_history_attachment_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whmcs_history_attachments');
    }
};
