<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_image_requests', function (Blueprint $table) {
            $table->unsignedTinyInteger('progress')->default(5)->after('status');
            $table->string('progress_step', 40)->default('queued')->after('progress');
            $table->timestamp('started_at')->nullable()->after('error');
            $table->timestamp('completed_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('product_image_requests', function (Blueprint $table) {
            $table->dropColumn(['progress', 'progress_step', 'started_at', 'completed_at']);
        });
    }
};
