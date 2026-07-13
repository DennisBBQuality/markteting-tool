<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cs_ticket_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id');
            $table->enum('richting', ['inkomend', 'uitgaand']);
            $table->enum('kanaal', ['handmatig'])->default('handmatig');
            $table->uuid('auteur_id')->nullable();
            $table->text('inhoud');
            $table->uuid('client_message_id');
            $table->timestamps();

            $table->foreign('ticket_id')
                ->references('id')
                ->on('cs_tickets')
                ->cascadeOnDelete();
            $table->foreign('auteur_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->unique(['ticket_id', 'client_message_id']);
            $table->index(['ticket_id', 'created_at']);
        });

        Schema::create('cs_ticket_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id');
            $table->uuid('auteur_id')->nullable();
            $table->text('inhoud');
            $table->timestamps();

            $table->foreign('ticket_id')
                ->references('id')
                ->on('cs_tickets')
                ->cascadeOnDelete();
            $table->foreign('auteur_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index(['ticket_id', 'created_at']);
        });

        Schema::create('cs_ticket_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id');
            $table->uuid('gebruiker_id')->nullable();
            $table->string('actie', 50);
            $table->json('details')->nullable();
            $table->timestamp('created_at');

            $table->foreign('ticket_id')
                ->references('id')
                ->on('cs_tickets')
                ->cascadeOnDelete();
            $table->foreign('gebruiker_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cs_ticket_activities');
        Schema::dropIfExists('cs_ticket_notes');
        Schema::dropIfExists('cs_ticket_messages');
    }
};
