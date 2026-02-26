<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Note;
use Illuminate\Http\Request;

class NoteController extends Controller
{
    public function index(Request $request)
    {
        $query = Note::select('notes.*', 'u.naam as aangemaakt_door_naam', 'p.naam as project_naam')
            ->leftJoin('users as u', 'notes.aangemaakt_door', '=', 'u.id')
            ->leftJoin('projects as p', 'notes.project_id', '=', 'p.id');

        if ($request->filled('project_id')) $query->where('notes.project_id', $request->project_id);
        if ($request->filled('task_id')) $query->where('notes.task_id', $request->task_id);
        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('notes.titel', 'like', $search)
                  ->orWhere('notes.inhoud', 'like', $search);
            });
        }

        return response()->json($query->orderByDesc('notes.created_at')->get());
    }

    public function store(Request $request)
    {
        $request->validate(['titel' => 'required|string']);

        $note = Note::create([
            'project_id' => $request->project_id,
            'task_id' => $request->task_id,
            'titel' => $request->titel,
            'inhoud' => $request->inhoud ?? '',
            'kleur' => $request->kleur ?? '#FEF3C7',
            'aangemaakt_door' => $request->session()->get('userId'),
        ]);

        return response()->json($note);
    }

    public function update(Request $request, string $id)
    {
        $note = Note::findOrFail($id);
        $note->update([
            'project_id' => $request->project_id,
            'task_id' => $request->task_id,
            'titel' => $request->titel,
            'inhoud' => $request->inhoud,
            'kleur' => $request->kleur,
        ]);

        return response()->json($note->fresh());
    }

    public function destroy(string $id)
    {
        Note::destroy($id);
        return response()->json(['ok' => true]);
    }
}
