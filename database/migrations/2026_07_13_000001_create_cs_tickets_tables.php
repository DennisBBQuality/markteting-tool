<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cs_ticket_counters', function (Blueprint $table) {
            $table->unsignedInteger('jaar')->primary();
            $table->unsignedInteger('laatste_nummer')->default(0);
        });

        Schema::create('cs_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('ticketnummer', 20)->unique();
            $table->string('onderwerp', 255);
            $table->string('klant_naam', 255);
            $table->string('klant_email', 255);
            $table->enum('status', [
                'nieuw',
                'in_behandeling',
                'wachten_op_klant',
                'afgehandeld',
            ])->default('nieuw');
            $table->enum('prioriteit', [
                'laag',
                'normaal',
                'hoog',
                'urgent',
            ])->default('normaal');
            $table->uuid('behandelaar_id')->nullable();
            $table->uuid('aangemaakt_door')->nullable();
            $table->unsignedInteger('versie')->default(1);
            $table->timestamp('laatste_bericht_op')->nullable();
            $table->timestamp('afgehandeld_op')->nullable();
            $table->timestamps();

            $table->foreign('behandelaar_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('aangemaakt_door')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index('status');
            $table->index('prioriteit');
            $table->index('behandelaar_id');
            $table->index('laatste_bericht_op');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cs_tickets');
        Schema::dropIfExists('cs_ticket_counters');
    }
};
