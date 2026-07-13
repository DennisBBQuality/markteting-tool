<?php

namespace App\Services\CustomerService;

use App\Models\CustomerService\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TicketWorkflowService
{
    private const PRIORITIES = ['laag', 'normaal', 'hoog', 'urgent'];

    public function __construct(private readonly TicketActivityLogger $activityLogger) {}

    public function claim(Ticket $ticket, User|string $user, int $versie): Ticket
    {
        return DB::transaction(function () use ($ticket, $user, $versie): Ticket {
            $current = $this->current($ticket);
            $userId = $this->userId($user);

            if ($current->status === 'afgehandeld') {
                throw $this->conflict(
                    'claim_conflict',
                    $current,
                    'Een afgehandeld ticket kan niet worden geclaimd.'
                );
            }

            if ($current->behandelaar_id !== null) {
                throw $this->conflict(
                    'claim_conflict',
                    $current,
                    'Dit ticket is al geclaimd door een collega.'
                );
            }

            $newStatus = $current->status === 'nieuw' ? 'in_behandeling' : $current->status;
            $updated = Ticket::query()
                ->whereKey($current->id)
                ->whereNull('behandelaar_id')
                ->where('versie', $versie)
                ->update([
                    'behandelaar_id' => $userId,
                    'status' => $newStatus,
                    'versie' => $versie + 1,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                $latest = $this->current($ticket);

                if ($latest->behandelaar_id !== null) {
                    throw $this->conflict(
                        'claim_conflict',
                        $latest,
                        'Dit ticket is al geclaimd door een collega.'
                    );
                }

                throw $this->versionConflict($latest);
            }

            $current = $this->current($ticket);
            $this->activityLogger->log($current, $user, 'ticket_geclaimd', [
                'behandelaar_id' => $userId,
            ]);

            if ($newStatus !== $ticket->status) {
                $this->activityLogger->log($current, $user, 'status_gewijzigd', [
                    'van' => $ticket->status,
                    'naar' => $newStatus,
                    'automatisch' => true,
                ]);
            }

            return $this->current($ticket);
        });
    }

    public function release(Ticket $ticket, User|string $user, int $versie): Ticket
    {
        return DB::transaction(function () use ($ticket, $user, $versie): Ticket {
            $current = $this->current($ticket);
            $userId = $this->userId($user);

            if ($current->status === 'afgehandeld') {
                throw $this->conflict(
                    'claim_conflict',
                    $current,
                    'Een afgehandeld ticket kan niet worden vrijgegeven.'
                );
            }

            if ($current->behandelaar_id !== $userId) {
                throw $this->conflict(
                    'claim_conflict',
                    $current,
                    'Alleen de huidige behandelaar kan dit ticket vrijgeven.'
                );
            }

            if ($current->versie !== $versie) {
                throw $this->versionConflict($current);
            }

            $newStatus = $current->status === 'in_behandeling' ? 'nieuw' : $current->status;
            $updated = Ticket::query()
                ->whereKey($current->id)
                ->where('behandelaar_id', $userId)
                ->where('versie', $versie)
                ->update([
                    'behandelaar_id' => null,
                    'status' => $newStatus,
                    'versie' => $versie + 1,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                throw $this->versionConflict($this->current($ticket));
            }

            $current = $this->current($ticket);
            $this->activityLogger->log($current, $user, 'ticket_vrijgegeven', [
                'behandelaar_id' => $userId,
            ]);

            if ($newStatus !== $ticket->status) {
                $this->activityLogger->log($current, $user, 'status_gewijzigd', [
                    'van' => $ticket->status,
                    'naar' => $newStatus,
                    'automatisch' => true,
                ]);
            }

            return $this->current($ticket);
        });
    }

    public function changeStatus(
        Ticket $ticket,
        User|string $user,
        string $status,
        int $versie,
    ): Ticket {
        return DB::transaction(function () use ($ticket, $user, $status, $versie): Ticket {
            $current = $this->current($ticket);

            if ($current->versie !== $versie) {
                throw $this->versionConflict($current);
            }

            if (! $this->isAllowedStatusTransition($current, $status)) {
                throw $this->conflict(
                    'invalid_status_transition',
                    $current,
                    "Statusovergang van '{$current->status}' naar '{$status}' is niet toegestaan."
                );
            }

            $attributes = [
                'status' => $status,
                'versie' => $versie + 1,
                'updated_at' => now(),
                'afgehandeld_op' => $status === 'afgehandeld' ? now() : null,
            ];

            if ($current->status === 'afgehandeld'
                && $status === 'in_behandeling'
                && $current->behandelaar_id === null) {
                $attributes['behandelaar_id'] = $this->userId($user);
            }

            $updated = Ticket::query()
                ->whereKey($current->id)
                ->where('versie', $versie)
                ->update($attributes);

            if ($updated === 0) {
                throw $this->versionConflict($this->current($ticket));
            }

            $current = $this->current($ticket);
            $this->activityLogger->log($current, $user, 'status_gewijzigd', [
                'van' => $ticket->status,
                'naar' => $status,
                'automatisch' => false,
            ]);

            return $this->current($ticket);
        });
    }

    public function changePriority(
        Ticket $ticket,
        User|string $user,
        string $prioriteit,
        int $versie,
    ): Ticket {
        if (! in_array($prioriteit, self::PRIORITIES, true)) {
            throw new InvalidArgumentException('Ongeldige ticketprioriteit.');
        }

        return DB::transaction(function () use ($ticket, $user, $prioriteit, $versie): Ticket {
            $current = $this->current($ticket);

            if ($current->versie !== $versie) {
                throw $this->versionConflict($current);
            }

            $updated = Ticket::query()
                ->whereKey($current->id)
                ->where('versie', $versie)
                ->update([
                    'prioriteit' => $prioriteit,
                    'versie' => $versie + 1,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                throw $this->versionConflict($this->current($ticket));
            }

            $current = $this->current($ticket);
            $this->activityLogger->log($current, $user, 'prioriteit_gewijzigd', [
                'van' => $ticket->prioriteit,
                'naar' => $prioriteit,
            ]);

            return $this->current($ticket);
        });
    }

    private function isAllowedStatusTransition(Ticket $ticket, string $status): bool
    {
        if ($ticket->status === $status) {
            return false;
        }

        return match ($ticket->status) {
            'nieuw' => $status === 'afgehandeld'
                || ($status === 'in_behandeling' && $ticket->behandelaar_id !== null),
            'in_behandeling' => in_array($status, ['wachten_op_klant', 'afgehandeld'], true),
            'wachten_op_klant' => in_array($status, ['in_behandeling', 'afgehandeld'], true),
            'afgehandeld' => $status === 'in_behandeling',
            default => false,
        };
    }

    private function current(Ticket $ticket): Ticket
    {
        return Ticket::query()->findOrFail($ticket->id);
    }

    private function userId(User|string $user): string
    {
        return $user instanceof User ? $user->id : $user;
    }

    private function versionConflict(Ticket $ticket): TicketConflictException
    {
        return $this->conflict(
            'version_conflict',
            $ticket,
            'Het ticket is intussen gewijzigd. Vernieuw en probeer opnieuw.'
        );
    }

    private function conflict(
        string $machineCode,
        Ticket $ticket,
        string $message,
    ): TicketConflictException {
        return new TicketConflictException($machineCode, $ticket, $message);
    }
}
