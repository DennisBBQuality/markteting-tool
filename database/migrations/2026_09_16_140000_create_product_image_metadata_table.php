<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_image_metadata', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_image_asset_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('image_version');
            $table->json('fields')->nullable();
            $table->string('source')->default('none');
            $table->string('status')->default('idle');
            $table->text('error')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->uuid('job_token')->nullable();
            $table->timestamps();
            $table->unique(['product_image_asset_id', 'image_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_image_metadata');
    }
};
