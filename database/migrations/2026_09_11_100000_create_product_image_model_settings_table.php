<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_image_model_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('model', 120);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_image_model_settings');
    }
};
