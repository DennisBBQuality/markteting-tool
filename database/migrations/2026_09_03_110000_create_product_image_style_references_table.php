<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_image_style_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('product_name', 160);
            $table->string('product_key', 160);
            $table->string('product_type', 20);
            $table->string('status', 20);
            $table->string('style_id', 60)->nullable();
            $table->foreignId('source_asset_id')->nullable()->unique()
                ->constrained('product_image_assets')->nullOnDelete();
            $table->unsignedInteger('source_version')->default(1);
            $table->foreignUuid('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('mime_type', 100)->default('image/png');
            $table->longText('contents_base64');
            $table->timestamps();

            $table->index(['product_key', 'status', 'style_id'], 'product_style_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_image_style_references');
    }
};
