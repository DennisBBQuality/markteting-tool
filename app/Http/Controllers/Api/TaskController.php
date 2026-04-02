<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $query = Task::with(['toegewezenen:id,naam,kleur', 'project:id,naam,kleur']);

        if ($request->filled('project_id')) $query->where('project_id', $request->project_id);
        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('prioriteit')) $query->where('prioriteit', $request->prioriteit);
        if ($request->filled('toegewezen_aan')) {
            $query->whereHas('toegewezenen', fn($q) => $q->where('users.id', $request->toegewezen_aan));
        }
        if ($request->filled('deadline_van')) $query->where('deadline', '>=', $request->deadline_van);
        if ($request->filled('deadline_tot')) $query->where('deadline', '<=', $request->deadline_tot);
        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('titel', 'like', $search)
                  ->orWhere('beschrijving', 'like', $search);
            });
        }

        return response()->json(
            $query->orderBy('positie')->orderByDesc('created_at')->get()
        );
    }

    public function store(Request $request)
    {
        $request->validate(['titel' => 'required|string']);

        $status = $request->status ?? 'todo';
        $maxPos = DB::table('tasks')->where('status', $status)->max('positie') ?? 0;

        $task = Task::create([
            'project_id' => $request->project_id,
            'titel' => $request->titel,
            'beschrijving' => $request->beschrijving ?? '',
            'status' => $status,
            'prioriteit' => $request->prioriteit ?? 'normaal',
            'deadline' => $request->deadline,
            'kleur' => $request->kleur,
            'positie' => $maxPos + 1,
        ]);

        $task->toegewezenen()->sync($this->parseAssignees($request));

        return response()->json(
            $task->load(['toegewezenen:id,naam,kleur', 'project:id,naam,kleur'])
        );
    }

    public function update(Request $request, string $id)
    {
        $task = Task::findOrFail($id);
        $task->update([
            'titel' => $request->titel,
            'beschrijving' => $request->beschrijving,
            'status' => $request->status,
            'prioriteit' => $request->prioriteit,
            'deadline' => $request->deadline,
            'kleur' => $request->kleur,
            'positie' => $request->positie ?? 0,
            'project_id' => $request->project_id,
        ]);

        $task->toegewezenen()->sync($this->parseAssignees($request));

        return response()->json(
            $task->load(['toegewezenen:id,naam,kleur', 'project:id,naam,kleur'])
        );
    }

    public function reorderBatch(Request $request)
    {
        foreach ($request->tasks as $item) {
            Task::where('id', $item['id'])->update([
                'status' => $item['status'],
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

    private function parseAssignees(Request $request): array
    {
        $value = $request->toegewezen_aan;

        if (is_array($value)) {
            return array_filter($value);
        }

        if (is_string($value) && $value !== '') {
            return [$value];
        }

        return [];
    }
}
