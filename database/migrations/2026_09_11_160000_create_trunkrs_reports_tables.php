<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trunkrs_connections', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->text('refresh_token')->nullable();
            $table->string('configuration_hash', 64)->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->string('last_error', 80)->nullable();
            $table->timestamps();
        });
        Schema::create('trunkrs_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('message_hash', 64)->unique();
            $table->string('content_hash', 64)->unique();
            $table->date('report_date')->nullable()->index();
            $table->timestamp('received_at');
            $table->unsignedInteger('shipment_count');
            // Only the required columns, encrypted at rest; never raw mail/ZIP.
            $table->longText('shipments');
            $table->string('parser_version', 40);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trunkrs_reports');
        Schema::dropIfExists('trunkrs_connections');
    }
};
