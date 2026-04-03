<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $query = Task::select('tasks.*',
                'p.naam as project_naam', 'p.kleur as project_kleur')
            ->leftJoin('projects as p', 'tasks.project_id', '=', 'p.id');

        if ($request->filled('project_id')) $query->where('tasks.project_id', $request->project_id);
        if ($request->filled('status')) $query->where('tasks.status', $request->status);
        if ($request->filled('prioriteit')) $query->where('tasks.prioriteit', $request->prioriteit);
        if ($request->filled('toegewezen_aan') && Schema::hasTable('taak_gebruiker')) {
            $userId = $request->toegewezen_aan;
            $query->whereExists(function ($q) use ($userId) {
                $q->select(DB::raw(1))
                  ->from('taak_gebruiker')
                  ->whereColumn('taak_gebruiker.task_id', 'tasks.id')
                  ->where('taak_gebruiker.user_id', $userId);
            });
        }
        if ($request->filled('deadline_van')) $query->where('tasks.deadline', '>=', $request->deadline_van);
        if ($request->filled('deadline_tot')) $query->where('tasks.deadline', '<=', $request->deadline_tot);
        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('tasks.titel', 'like', $search)
                  ->orWhere('tasks.beschrijving', 'like', $search);
            });
        }

        $tasks = $query->orderBy('tasks.positie')->orderByDesc('tasks.created_at')->get();

        return response()->json($this->appendToegewezenen($tasks));
    }

    public function store(Request $request)
    {
        $request->validate(['titel' => 'required|string']);

        $status = $request->status ?? 'todo';
        $maxPos = DB::table('tasks')->where('status', $status)->max('positie') ?? 0;

        $task = Task::create([
            'project_id'  => $request->project_id,
            'titel'       => $request->titel,
            'beschrijving'=> $request->beschrijving ?? '',
            'status'      => $status,
            'prioriteit'  => $request->prioriteit ?? 'normaal',
            'deadline'    => $request->deadline,
            'kleur'       => $request->kleur,
            'positie'     => $maxPos + 1,
        ]);

        $this->syncToegewezenen($task->id, $request);

        $result = Task::select('tasks.*', 'p.naam as project_naam', 'p.kleur as project_kleur')
            ->leftJoin('projects as p', 'tasks.project_id', '=', 'p.id')
            ->where('tasks.id', $task->id)->get();

        return response()->json($this->appendToegewezenen($result)->first());
    }

    public function update(Request $request, string $id)
    {
        $task = Task::findOrFail($id);
        $task->update([
            'titel'        => $request->titel,
            'beschrijving' => $request->beschrijving,
            'status'       => $request->status,
            'prioriteit'   => $request->prioriteit,
            'deadline'     => $request->deadline,
            'kleur'        => $request->kleur,
            'positie'      => $request->positie ?? 0,
            'project_id'   => $request->project_id,
        ]);

        $this->syncToegewezenen($id, $request);

        $result = Task::select('tasks.*', 'p.naam as project_naam', 'p.kleur as project_kleur')
            ->leftJoin('projects as p', 'tasks.project_id', '=', 'p.id')
            ->where('tasks.id', $id)->get();

        return response()->json($this->appendToegewezenen($result)->first());
    }

    public function reorderBatch(Request $request)
    {
        foreach ($request->tasks as $item) {
            Task::where('id', $item['id'])->update([
                'status'  => $item['status'],
                'positie' => $item['positie'],
            ]);
        }
        return response()->json(['ok' => true]);
    }

    public function destroy(string $id)
    {
        Task::destroy($id);
        return response()->json(['ok' => true]);
    }

    private function appendToegewezenen($tasks)
    {
        if (!Schema::hasTable('taak_gebruiker')) {
            return $tasks->map(function ($task) {
                $task->toegewezenen = [];
                return $task;
            });
        }

        $taskIds = $tasks->pluck('id');

        $pivotData = DB::table('taak_gebruiker')
            ->join('users', 'taak_gebruiker.user_id', '=', 'users.id')
            ->whereIn('taak_gebruiker.task_id', $taskIds)
            ->select('taak_gebruiker.task_id', 'users.id', 'users.naam', 'users.kleur')
            ->get()
            ->groupBy('task_id');

        return $tasks->map(function ($task) use ($pivotData) {
            $task->toegewezenen = ($pivotData->get($task->id) ?? collect())
                ->map(fn($u) => ['id' => $u->id, 'naam' => $u->naam, 'kleur' => $u->kleur])
                ->values();
            return $task;
        });
    }

    private function syncToegewezenen(string $taskId, Request $request): void
    {
        if (!Schema::hasTable('taak_gebruiker')) return;
        $value = $request->toegewezen_aan;
        $ids = match (true) {
            is_array($value) => array_filter($value),
            is_string($value) && $value !== '' => [$value],
            default => [],
        };

        DB::table('taak_gebruiker')->where('task_id', $taskId)->delete();
        foreach ($ids as $userId) {
            DB::table('taak_gebruiker')->insert([
                'id'         => (string) Str::uuid(),
                'task_id'    => $taskId,
                'user_id'    => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
