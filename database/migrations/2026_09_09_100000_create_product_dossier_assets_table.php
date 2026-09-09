<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_dossier_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_dossier_id')->constrained()->cascadeOnDelete();
            $table->string('mime_type');
            $table->longText('contents_base64');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_dossier_assets');
    }
};
