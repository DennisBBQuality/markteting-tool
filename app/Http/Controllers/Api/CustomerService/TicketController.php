<?php

namespace App\Http\Controllers\Api\CustomerService;

use App\Http\Requests\CustomerService\IndexTicketRequest;
use App\Http\Requests\CustomerService\StoreTicketRequest;
use App\Models\CustomerService\Ticket;
use App\Models\User;
use App\Services\CustomerService\TicketActivityLogger;
use App\Services\CustomerService\TicketMessageService;
use App\Services\CustomerService\TicketNumberService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TicketController extends CustomerServiceController
{
    public function __construct(
        private readonly TicketNumberService $numberService,
        private readonly TicketMessageService $messageService,
        private readonly TicketActivityLogger $activityLogger,
    ) {}

    public function index(IndexTicketRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $query = Ticket::query()->with(['behandelaar', 'aangemaaktDoor']);

        if (! isset($validated['status'])) {
            $query->where('status', '!=', 'afgehandeld');
        } elseif ($validated['status'] !== 'alle') {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['prioriteit'])) {
            $query->where('prioriteit', $validated['prioriteit']);
        }

        if ($request->boolean('niet_toegewezen')) {
            $query->whereNull('behandelaar_id');
        } elseif (isset($validated['behandelaar_id'])) {
            $query->where('behandelaar_id', $validated['behandelaar_id']);
        }

        if (isset($validated['zoek'])) {
            $search = '%'.$validated['zoek'].'%';
            $query->where(function (Builder $query) use ($search): void {
                $query->where('ticketnummer', 'like', $search)
                    ->orWhere('onderwerp', 'like', $search)
                    ->orWhere('klant_naam', 'like', $search)
                    ->orWhere('klant_email', 'like', $search);
            });
        }

        $this->applySorting(
            $query,
            $validated['sorteer'] ?? 'laatste_bericht',
            $validated['richting'] ?? 'desc'
        );

        $perPage = (int) ($validated['per_page'] ?? 25);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (Ticket $ticket): array => $this->ticketData($ticket))
                ->values(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(StoreTicketRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = User::query()->findOrFail($request->session()->get('userId'));

        $ticket = DB::transaction(function () use ($validated, $user): Ticket {
            $ticket = Ticket::query()->create([
                'ticketnummer' => $this->numberService->next(),
                'onderwerp' => $validated['onderwerp'],
                'klant_naam' => $validated['klant_naam'],
                'klant_email' => $validated['klant_email'],
                'prioriteit' => $validated['prioriteit'] ?? 'normaal',
                'aangemaakt_door' => $user->id,
            ]);
            $ticket->refresh();

            $this->activityLogger->log($ticket, $user, 'ticket_aangemaakt', [
                'prioriteit' => $ticket->prioriteit,
            ]);
            $this->messageService->addInitialMessage(
                $ticket,
                $user,
                $validated['eerste_bericht']
            );

            return $ticket->fresh(['behandelaar', 'aangemaaktDoor']);
        });

        return response()->json($this->ticketData($ticket), 201);
    }

    public function show(string $id): JsonResponse
    {
        $ticket = Ticket::query()
            ->with(['behandelaar', 'aangemaaktDoor'])
            ->findOrFail($id);

        return response()->json($this->ticketData($ticket));
    }

    private function applySorting(Builder $query, string $sort, string $direction): void
    {
        if ($sort === 'aangemaakt') {
            $query->orderBy('created_at', $direction);

            return;
        }

        if ($sort === 'prioriteit') {
            $query->orderByRaw(
                "CASE prioriteit WHEN 'urgent' THEN 1 WHEN 'hoog' THEN 2 WHEN 'normaal' THEN 3 ELSE 4 END"
            )->orderByDesc('laatste_bericht_op');

            return;
        }

        $query->orderByRaw('CASE WHEN laatste_bericht_op IS NULL THEN 1 ELSE 0 END')
            ->orderBy('laatste_bericht_op', $direction)
            ->orderBy('created_at', $direction);
    }
}
