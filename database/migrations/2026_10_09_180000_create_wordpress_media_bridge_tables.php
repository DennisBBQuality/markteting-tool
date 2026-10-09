<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wordpress_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('destination')->unique();
            $table->string('username')->default('pitboard');
            $table->text('password')->nullable();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('tested_at')->nullable();
            $table->timestamps();
        });
        Schema::create('wordpress_media_transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('connection_id');
            $table->unsignedBigInteger('asset_id');
            $table->unsignedInteger('image_version');
            $table->string('approved_fingerprint', 64)->nullable();
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('uploaded_fingerprint', 64)->nullable();
            $table->unsignedBigInteger('attachment_id')->nullable();
            $table->text('remote_url')->nullable();
            $table->string('status')->default('idle');
            $table->uuid('claim')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
            $table->unique(['connection_id', 'asset_id', 'image_version'], 'wordpress_media_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_media_transfers');
        Schema::dropIfExists('wordpress_connections');
    }
};
