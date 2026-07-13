<?php

namespace Database\Seeders;

use App\Models\CustomerService\Ticket;
use App\Models\User;
use App\Services\CustomerService\TicketActivityLogger;
use App\Services\CustomerService\TicketMessageService;
use App\Services\CustomerService\TicketNumberService;
use App\Services\CustomerService\TicketWorkflowService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CustomerServiceTestSeeder extends Seeder
{
    public function run(): void
    {
        $users = $this->users();
        $numberService = app(TicketNumberService::class);
        $messageService = app(TicketMessageService::class);
        $workflowService = app(TicketWorkflowService::class);
        $activityLogger = app(TicketActivityLogger::class);

        Ticket::query()
            ->where('klant_email', 'like', 'cs-test-%@example.test')
            ->delete();

        $statuses = ['nieuw', 'in_behandeling', 'wachten_op_klant', 'afgehandeld'];
        $priorities = ['laag', 'normaal', 'hoog', 'urgent'];

        for ($index = 1; $index <= 16; $index++) {
            $user = $users[($index - 1) % $users->count()];
            $status = $statuses[($index - 1) % count($statuses)];
            $priority = $priorities[($index - 1) % count($priorities)];

            $ticket = DB::transaction(function () use (
                $index,
                $priority,
                $user,
                $numberService,
                $messageService,
                $activityLogger,
            ): Ticket {
                $ticket = Ticket::query()->create([
                    'ticketnummer' => $numberService->next(),
                    'onderwerp' => 'Testvraag klantenservice '.$index,
                    'klant_naam' => 'Testklant '.$index,
                    'klant_email' => sprintf('cs-test-%02d@example.test', $index),
                    'prioriteit' => $priority,
                    'aangemaakt_door' => $user->id,
                ]);
                $ticket->refresh();

                $activityLogger->log($ticket, $user, 'ticket_aangemaakt', [
                    'prioriteit' => $priority,
                ]);
                $messageService->addInitialMessage(
                    $ticket,
                    $user,
                    'Dit is het eerste fictieve klantbericht voor testticket '.$index.'.'
                );

                return $ticket->fresh();
            });

            $ticket = $workflowService->claim($ticket, $user, $ticket->versie);
            $result = $messageService->addMessage($ticket, $user, [
                'richting' => 'uitgaand',
                'inhoud' => 'Dit is een fictief antwoord van het klantenserviceteam.',
                'client_message_id' => (string) Str::uuid(),
                'versie' => $ticket->versie,
            ]);
            $ticket = $result['ticket'];
            $result = $messageService->addMessage($ticket, $user, [
                'richting' => 'inkomend',
                'inhoud' => 'Dit is een fictieve vervolgvraag van de testklant.',
                'client_message_id' => (string) Str::uuid(),
                'versie' => $ticket->versie,
            ]);
            $ticket = $result['ticket'];
            $result = $messageService->addNote(
                $ticket,
                $user,
                'Interne testnotitie; bevat geen echte klantgegevens.',
                $ticket->versie
            );
            $ticket = $result['ticket'];

            $ticket = match ($status) {
                'nieuw' => $workflowService->release($ticket, $user, $ticket->versie),
                'wachten_op_klant' => $workflowService->changeStatus(
                    $ticket,
                    $user,
                    'wachten_op_klant',
                    $ticket->versie
                ),
                'afgehandeld' => $workflowService->changeStatus(
                    $ticket,
                    $user,
                    'afgehandeld',
                    $ticket->versie
                ),
                default => $ticket,
            };
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function users(): Collection
    {
        $users = User::query()->where('actief', true)->get();

        if ($users->isNotEmpty()) {
            return $users;
        }

        return collect([
            User::query()->create([
                'naam' => 'Testmedewerker Klantenservice',
                'email' => 'cs-medewerker@example.test',
                'wachtwoord_hash' => Hash::make(Str::random(40)),
                'rol' => 'lid',
                'kleur' => '#3B82F6',
                'actief' => true,
            ]),
        ]);
    }
}
