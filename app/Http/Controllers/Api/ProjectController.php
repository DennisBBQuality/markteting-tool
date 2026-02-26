<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatChannel;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    public function index()
    {
        $projects = DB::select("
            SELECT p.*, u.naam as aangemaakt_door_naam,
                (SELECT COUNT(*) FROM tasks WHERE project_id = p.id) as aantal_taken,
                (SELECT COUNT(*) FROM tasks WHERE project_id = p.id AND status = 'klaar') as taken_klaar
            FROM projects p
            LEFT JOIN users u ON p.aangemaakt_door = u.id
            ORDER BY p.created_at DESC
        ");

        return response()->json($projects);
    }

    public function store(Request $request)
    {
        $request->validate(['naam' => 'required|string']);

        $project = Project::create([
            'naam' => $request->naam,
            'beschrijving' => $request->beschrijving ?? '',
            'kleur' => $request->kleur ?? '#3B82F6',
            'prioriteit' => $request->prioriteit ?? 'normaal',
            'deadline' => $request->deadline,
            'aangemaakt_door' => $request->session()->get('userId'),
        ]);

        ChatChannel::create([
            'naam' => $request->naam,
            'type' => 'project',
            'project_id' => $project->id,
            'aangemaakt_door' => $request->session()->get('userId'),
        ]);

        return response()->json($project);
    }

    public function update(Request $request, string $id)
    {
        $project = Project::findOrFail($id);
        $project->update([
            'naam' => $request->naam,
            'beschrijving' => $request->beschrijving,
            'kleur' => $request->kleur,
            'status' => $request->status,
            'prioriteit' => $request->prioriteit,
            'deadline' => $request->deadline,
        ]);

        return response()->json($project->fresh());
    }

    public function destroy(string $id)
    {
        Project::destroy($id);
        return response()->json(['ok' => true]);
    }
}
