<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_dossiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 30)->default('concept')->index();
            $table->string('product_type', 50)->nullable()->index();
            $table->string('product_name', 200)->nullable();
            $table->json('data')->nullable();
            $table->json('label_images')->nullable();
            $table->json('label_analysis')->nullable();
            $table->string('analysis_status', 30)->default('niet_gestart');
            $table->text('analysis_error')->nullable();
            $table->string('wordpress_status', 30)->default('niet_gekoppeld');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_dossiers');
    }
};
