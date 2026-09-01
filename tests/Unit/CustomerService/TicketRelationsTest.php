<?php

namespace Tests\Unit\CustomerService;

use App\Models\CustomerService\Ticket;
use App\Models\CustomerService\TicketActivity;
use App\Models\CustomerService\TicketMessage;
use App\Models\CustomerService\TicketNote;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class TicketRelationsTest extends TestCase
{
    use RefreshDatabase;

    private int $ticketSequence = 0;

    public function test_migration_creates_and_rolls_back_all_detail_tables(): void
    {
        $this->assertTrue(Schema::hasTable('cs_ticket_messages'));
        $this->assertTrue(Schema::hasTable('cs_ticket_notes'));
        $this->assertTrue(Schema::hasTable('cs_ticket_activities'));

        try {
            $this->artisan('migrate:rollback', ['--step' => 2])->assertSuccessful();

            $this->assertFalse(Schema::hasTable('cs_ticket_activities'));
            $this->assertFalse(Schema::hasTable('cs_ticket_notes'));
            $this->assertFalse(Schema::hasTable('cs_ticket_messages'));
            $this->assertTrue(Schema::hasTable('cs_tickets'));
            $this->assertTrue(Schema::hasTable('cs_ticket_counters'));
        } finally {
            $this->artisan('migrate')->assertSuccessful();
        }

        $this->assertTrue(Schema::hasTable('cs_ticket_messages'));
        $this->assertTrue(Schema::hasTable('cs_ticket_notes'));
        $this->assertTrue(Schema::hasTable('cs_ticket_activities'));
    }

    public function test_detail_tables_have_exact_columns_indexes_and_foreign_keys(): void
    {
        $this->assertSame([
            'id',
            'ticket_id',
            'richting',
            'kanaal',
            'auteur_id',
            'inhoud',
            'client_message_id',
            'created_at',
            'updated_at',
        ], Schema::getColumnListing('cs_ticket_messages'));
        $this->assertSame([
            'id',
            'ticket_id',
            'auteur_id',
            'inhoud',
            'created_at',
            'updated_at',
        ], Schema::getColumnListing('cs_ticket_notes'));
        $this->assertSame([
            'id',
            'ticket_id',
            'gebruiker_id',
            'actie',
            'details',
            'created_at',
        ], Schema::getColumnListing('cs_ticket_activities'));

        $this->assertTrue(Schema::hasIndex('cs_ticket_messages', ['id'], 'primary'));
        $this->assertTrue(Schema::hasIndex(
            'cs_ticket_messages',
            ['ticket_id', 'client_message_id'],
            'unique'
        ));
        $this->assertTrue(Schema::hasIndex('cs_ticket_messages', ['ticket_id', 'created_at']));
        $this->assertTrue(Schema::hasIndex('cs_ticket_notes', ['id'], 'primary'));
        $this->assertTrue(Schema::hasIndex('cs_ticket_notes', ['ticket_id', 'created_at']));
        $this->assertTrue(Schema::hasIndex('cs_ticket_activities', ['id'], 'primary'));
        $this->assertTrue(Schema::hasIndex('cs_ticket_activities', ['ticket_id', 'created_at']));

        $this->assertForeignKey('cs_ticket_messages', 'ticket_id', 'cs_tickets', 'cascade');
        $this->assertForeignKey('cs_ticket_messages', 'auteur_id', 'users', 'set null');
        $this->assertForeignKey('cs_ticket_notes', 'ticket_id', 'cs_tickets', 'cascade');
        $this->assertForeignKey('cs_ticket_notes', 'auteur_id', 'users', 'set null');
        $this->assertForeignKey('cs_ticket_activities', 'ticket_id', 'cs_tickets', 'cascade');
        $this->assertForeignKey('cs_ticket_activities', 'gebruiker_id', 'users', 'set null');
    }

    public function test_detail_models_use_uuids_expected_configuration_and_activity_casts(): void
    {
        $ticket = $this->createTicket();
        $message = $this->createMessage($ticket);
        $note = $this->createNote($ticket);
        $activity = $this->createActivity($ticket, [
            'details' => ['prioriteit' => 'normaal'],
        ]);

        $this->assertTrue(Str::isUuid($message->id));
        $this->assertTrue(Str::isUuid($note->id));
        $this->assertTrue(Str::isUuid($activity->id));
        $this->assertFalse($message->getIncrementing());
        $this->assertFalse($note->getIncrementing());
        $this->assertFalse($activity->getIncrementing());
        $this->assertSame('cs_ticket_messages', $message->getTable());
        $this->assertSame('cs_ticket_notes', $note->getTable());
        $this->assertSame('cs_ticket_activities', $activity->getTable());
        $this->assertSame([
            'ticket_id',
            'richting',
            'kanaal',
            'auteur_id',
            'inhoud',
            'client_message_id',
        ], $message->getFillable());
        $this->assertSame([
            'ticket_id',
            'auteur_id',
            'inhoud',
        ], $note->getFillable());
        $this->assertSame([
            'ticket_id',
            'gebruiker_id',
            'actie',
            'details',
        ], $activity->getFillable());

        $message->refresh();
        $activity->refresh();

        $this->assertSame('handmatig', $message->kanaal);
        $this->assertSame(['prioriteit' => 'normaal'], $activity->details);
        $this->assertSame('array', $activity->getCasts()['details']);
        $this->assertSame('datetime', $activity->getCasts()['created_at']);
        $this->assertNotNull($activity->created_at);
        $this->assertNull($activity->getUpdatedAtColumn());
        $this->assertFalse(Schema::hasColumn('cs_ticket_activities', 'updated_at'));
        $this->assertArrayNotHasKey('updated_at', $activity->getAttributes());
    }

    public function test_model_relations_work_in_both_directions_and_use_specified_order(): void
    {
        $user = User::factory()->create();
        $ticket = $this->createTicket();
        $older = now()->subMinutes(2);
        $newer = now()->subMinute();

        $newerMessage = $this->createMessage($ticket, [
            'auteur_id' => $user->id,
            'inhoud' => 'Nieuwer bericht',
            'created_at' => $newer,
        ]);
        $olderMessage = $this->createMessage($ticket, [
            'auteur_id' => $user->id,
            'inhoud' => 'Ouder bericht',
            'created_at' => $older,
        ]);
        $newerNote = $this->createNote($ticket, [
            'auteur_id' => $user->id,
            'inhoud' => 'Nieuwere notitie',
            'created_at' => $newer,
        ]);
        $olderNote = $this->createNote($ticket, [
            'auteur_id' => $user->id,
            'inhoud' => 'Oudere notitie',
            'created_at' => $older,
        ]);
        $olderActivity = $this->createActivity($ticket, [
            'gebruiker_id' => $user->id,
            'actie' => 'ticket_aangemaakt',
            'created_at' => $older,
        ]);
        $newerActivity = $this->createActivity($ticket, [
            'gebruiker_id' => $user->id,
            'actie' => 'bericht_toegevoegd',
            'created_at' => $newer,
        ]);

        $this->assertTrue($olderMessage->ticket->is($ticket));
        $this->assertTrue($olderMessage->auteur->is($user));
        $this->assertTrue($olderNote->ticket->is($ticket));
        $this->assertTrue($olderNote->auteur->is($user));
        $this->assertTrue($olderActivity->ticket->is($ticket));
        $this->assertTrue($olderActivity->gebruiker->is($user));

        $this->assertSame(
            [$olderMessage->id, $newerMessage->id],
            $ticket->messages()->pluck('id')->all()
        );
        $this->assertSame(
            [$olderNote->id, $newerNote->id],
            $ticket->notes()->pluck('id')->all()
        );
        $this->assertSame(
            [$newerActivity->id, $olderActivity->id],
            $ticket->activities()->pluck('id')->all()
        );
    }

    public function test_deleting_a_ticket_cascades_all_detail_records_at_database_level(): void
    {
        $ticket = $this->createTicket();
        $message = $this->createMessage($ticket);
        $note = $this->createNote($ticket);
        $activity = $this->createActivity($ticket);

        DB::table('cs_tickets')->where('id', $ticket->id)->delete();

        $this->assertDatabaseMissing('cs_ticket_messages', ['id' => $message->id]);
        $this->assertDatabaseMissing('cs_ticket_notes', ['id' => $note->id]);
        $this->assertDatabaseMissing('cs_ticket_activities', ['id' => $activity->id]);
    }

    public function test_deleting_a_user_sets_all_detail_actor_foreign_keys_to_null(): void
    {
        $user = User::factory()->create();
        $ticket = $this->createTicket();
        $message = $this->createMessage($ticket, ['auteur_id' => $user->id]);
        $note = $this->createNote($ticket, ['auteur_id' => $user->id]);
        $activity = $this->createActivity($ticket, ['gebruiker_id' => $user->id]);

        DB::table('users')->where('id', $user->id)->delete();

        $this->assertNull($message->fresh()->auteur_id);
        $this->assertNull($note->fresh()->auteur_id);
        $this->assertNull($activity->fresh()->gebruiker_id);
    }

    public function test_client_message_id_is_unique_per_ticket_but_reusable_for_another_ticket(): void
    {
        $firstTicket = $this->createTicket();
        $secondTicket = $this->createTicket();
        $clientMessageId = (string) Str::uuid();

        $this->createMessage($firstTicket, ['client_message_id' => $clientMessageId]);
        $secondMessage = $this->createMessage($secondTicket, [
            'client_message_id' => $clientMessageId,
        ]);

        $this->assertDatabaseHas('cs_ticket_messages', ['id' => $secondMessage->id]);

        $this->expectException(QueryException::class);

        $this->createMessage($firstTicket, ['client_message_id' => $clientMessageId]);
    }

    public function test_richting_accepts_only_inkomend_and_uitgaand(): void
    {
        $ticket = $this->createTicket();

        $this->createMessage($ticket, ['richting' => 'inkomend']);
        $this->createMessage($ticket, ['richting' => 'uitgaand']);

        $this->expectException(QueryException::class);

        $this->createMessage($ticket, ['richting' => 'intern']);
    }

    public function test_kanaal_accepts_only_handmatig(): void
    {
        $ticket = $this->createTicket();
        $message = $this->createMessage($ticket, ['kanaal' => 'handmatig']);

        $this->assertSame('handmatig', $message->kanaal);

        $this->expectException(QueryException::class);

        $this->createMessage($ticket, ['kanaal' => 'email']);
    }

    private function createTicket(): Ticket
    {
        $this->ticketSequence++;

        return Ticket::query()->create([
            'ticketnummer' => sprintf('CS-2026-%05d', $this->ticketSequence),
            'onderwerp' => 'Testticket '.$this->ticketSequence,
            'klant_naam' => 'Test Klant',
            'klant_email' => 'klant'.$this->ticketSequence.'@example.test',
        ]);
    }

    private function createMessage(Ticket $ticket, array $attributes = []): TicketMessage
    {
        $message = new TicketMessage;
        $message->forceFill(array_merge([
            'ticket_id' => $ticket->id,
            'richting' => 'inkomend',
            'auteur_id' => null,
            'inhoud' => 'Testbericht',
            'client_message_id' => (string) Str::uuid(),
        ], $attributes));
        $message->save();

        return $message;
    }

    private function createNote(Ticket $ticket, array $attributes = []): TicketNote
    {
        $note = new TicketNote;
        $note->forceFill(array_merge([
            'ticket_id' => $ticket->id,
            'auteur_id' => null,
            'inhoud' => 'Testnotitie',
        ], $attributes));
        $note->save();

        return $note;
    }

    private function createActivity(Ticket $ticket, array $attributes = []): TicketActivity
    {
        $activity = new TicketActivity;
        $activity->forceFill(array_merge([
            'ticket_id' => $ticket->id,
            'gebruiker_id' => null,
            'actie' => 'ticket_aangemaakt',
            'details' => null,
        ], $attributes));
        $activity->save();

        return $activity;
    }

    private function assertForeignKey(
        string $table,
        string $column,
        string $foreignTable,
        string $onDelete,
    ): void {
        $foreignKey = collect(Schema::getForeignKeys($table))->first(
            fn (array $key): bool => $key['columns'] === [$column]
        );

        $this->assertNotNull($foreignKey);
        $this->assertSame($foreignTable, $foreignKey['foreign_table']);
        $this->assertSame(['id'], $foreignKey['foreign_columns']);
        $this->assertSame($onDelete, $foreignKey['on_delete']);
    }
}
