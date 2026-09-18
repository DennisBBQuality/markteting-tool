<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_image_download_names', function (Blueprint $table) {
            $table->string('filename', 185)->primary();
            $table->foreignId('product_image_asset_id')->constrained('product_image_assets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_image_download_names');
    }
};
