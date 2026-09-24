<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PitboardNotification;
use App\Services\AssignmentNotifications;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function initialize(Request $request, AssignmentNotifications $service)
    {
        $request->validate(['confirm' => 'accepted']);
        try {
            $exit = Artisan::call('pitboard:upgrade-notification-storage');
        } catch (\Throwable) {
            $exit = 1;
        }
        abort_if($exit !== 0 || ! $service->storageReady(), 503, 'De meldingenopslag kon niet veilig worden voorbereid. Er zijn geen bestaande gegevens vervangen.');

        return response()->json(['ok' => true]);
    }

    private function unavailable(Request $request)
    {
        return response()->json(['ready' => false, 'can_initialize' => $request->session()->get('rol') === 'admin'])
            ->header('Cache-Control', 'no-store');
    }

    private function inbox(Request $request)
    {
        return PitboardNotification::where('user_id', $request->session()->get('userId'));
    }

    private function present(PitboardNotification $notification): array
    {
        return $notification->only(['id', 'kind', 'target_id', 'title', 'actor_name', 'deadline', 'read_at', 'created_at']);
    }

    public function index(Request $request)
    {
        if (! app(AssignmentNotifications::class)->storageReady()) {
            return $this->unavailable($request);
        }
        $request->validate(['page' => 'sometimes|integer|min:1|max:10000', 'unread' => 'sometimes|boolean']);
        $query = $this->inbox($request);
        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(20);

        return response()->json([
            'items' => $page->getCollection()->map(fn ($row) => $this->present($row)),
            'unread_count' => $this->inbox($request)->whereNull('read_at')->count(),
            'has_more' => $page->hasMorePages(),
        ])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $id)
    {
        $notification = $this->inbox($request)->findOrFail($id);
        $table = $notification->kind === 'task' ? 'tasks' : 'projects';
        abort_unless(DB::table($table)->where('id', $notification->target_id)->exists(), 410, 'Deze taak of dit project is verwijderd.');

        return response()->json($this->present($notification))->header('Cache-Control', 'no-store');
    }

    public function read(Request $request, string $id)
    {
        $notification = $this->inbox($request)->findOrFail($id);
        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request)
    {
        $this->inbox($request)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function preferences(Request $request, AssignmentNotifications $service)
    {
        if (! $service->storageReady()) {
            abort_if($request->isMethod('put'), 503, 'De meldingenopslag is nog niet voorbereid.');

            return $this->unavailable($request);
        }
        $userId = $request->session()->get('userId');
        if ($request->isMethod('put')) {
            $data = $request->validate(['task_email' => 'required|boolean', 'project_email' => 'required|boolean']);
            DB::table('pitboard_notification_preferences')->upsert([
                array_merge($data, ['user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]),
            ], ['user_id'], ['task_email', 'project_email', 'updated_at']);
        }

        $health = [];
        if ($request->session()->get('rol') === 'admin') {
            $health = ['email_attention_count' => PitboardNotification::where('email_status', 'uncertain')
                ->orWhere(fn ($q) => $q->whereIn('email_status', ['pending', 'processing'])->where('created_at', '<', now()->subMinutes(10)))
                ->count()];
        }

        return response()->json([
            'task_email' => $service->wantsEmail($userId, 'task'),
            'project_email' => $service->wantsEmail($userId, 'project'),
            'email_active' => $service->emailReady(),
            'sender_name' => 'The Pitboard',
            ...$health,
        ])->header('Cache-Control', 'no-store');
    }
}
