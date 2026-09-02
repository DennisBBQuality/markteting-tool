<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_image_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('product_image_request_id')
                ->constrained('product_image_requests')
                ->cascadeOnDelete();
            $table->string('filename');
            $table->string('mime_type', 100)->default('image/png');
            $table->longText('contents_base64');
            $table->timestamps();

            $table->unique(['product_image_request_id', 'filename']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_image_assets');
    }
};
