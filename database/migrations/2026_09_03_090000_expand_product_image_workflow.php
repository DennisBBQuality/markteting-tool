<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_image_requests', function (Blueprint $table) {
            $table->json('source_references')->nullable()->after('source_path');
            $table->json('generation_context')->nullable()->after('prompt');
        });

        Schema::table('product_image_assets', function (Blueprint $table) {
            $table->string('style_id', 60)->nullable()->after('filename');
            $table->unsignedInteger('version')->default(1)->after('style_id');
            $table->string('refinement_status', 20)->default('idle')->after('version');
            $table->string('refinement_error')->nullable()->after('refinement_status');
            $table->text('last_instruction')->nullable()->after('refinement_error');
        });

        Schema::create('product_image_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_image_asset_id')->constrained('product_image_assets')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('instruction')->nullable();
            $table->string('mime_type', 100)->default('image/png');
            $table->longText('contents_base64');
            $table->timestamps();

            $table->unique(['product_image_asset_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_image_revisions');

        Schema::table('product_image_assets', function (Blueprint $table) {
            $table->dropColumn(['style_id', 'version', 'refinement_status', 'refinement_error', 'last_instruction']);
        });

        Schema::table('product_image_requests', function (Blueprint $table) {
            $table->dropColumn(['source_references', 'generation_context']);
        });
    }
};
