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
        $query = Task::select('tasks.*',
                'u.naam as toegewezen_naam', 'u.kleur as user_kleur',
                'p.naam as project_naam', 'p.kleur as project_kleur')
            ->leftJoin('users as u', 'tasks.toegewezen_aan', '=', 'u.id')
            ->leftJoin('projects as p', 'tasks.project_id', '=', 'p.id');

        if ($request->filled('project_id')) $query->where('tasks.project_id', $request->project_id);
        if ($request->filled('status')) $query->where('tasks.status', $request->status);
        if ($request->filled('prioriteit')) $query->where('tasks.prioriteit', $request->prioriteit);
        if ($request->filled('toegewezen_aan')) $query->where('tasks.toegewezen_aan', $request->toegewezen_aan);
        if ($request->filled('deadline_van')) $query->where('tasks.deadline', '>=', $request->deadline_van);
        if ($request->filled('deadline_tot')) $query->where('tasks.deadline', '<=', $request->deadline_tot);
        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('tasks.titel', 'like', $search)
                  ->orWhere('tasks.beschrijving', 'like', $search);
            });
        }

        return response()->json(
            $query->orderBy('tasks.positie')->orderByDesc('tasks.created_at')->get()
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
            'toegewezen_aan' => $request->toegewezen_aan,
            'deadline' => $request->deadline,
            'kleur' => $request->kleur,
            'positie' => $maxPos + 1,
        ]);

        return response()->json(
            Task::select('tasks.*',
                'u.naam as toegewezen_naam', 'u.kleur as user_kleur',
                'p.naam as project_naam', 'p.kleur as project_kleur')
            ->leftJoin('users as u', 'tasks.toegewezen_aan', '=', 'u.id')
            ->leftJoin('projects as p', 'tasks.project_id', '=', 'p.id')
            ->where('tasks.id', $task->id)->first()
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
            'toegewezen_aan' => $request->toegewezen_aan,
            'deadline' => $request->deadline,
            'kleur' => $request->kleur,
            'positie' => $request->positie ?? 0,
            'project_id' => $request->project_id,
        ]);

        return response()->json(
            Task::select('tasks.*',
                'u.naam as toegewezen_naam', 'u.kleur as user_kleur',
                'p.naam as project_naam', 'p.kleur as project_kleur')
            ->leftJoin('users as u', 'tasks.toegewezen_aan', '=', 'u.id')
            ->leftJoin('projects as p', 'tasks.project_id', '=', 'p.id')
            ->where('tasks.id', $id)->first()
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
}
