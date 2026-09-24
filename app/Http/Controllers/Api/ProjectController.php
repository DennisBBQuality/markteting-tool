<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\AssignmentNotifications;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        $projectIds = array_column($projects, 'id');

        if (Schema::hasTable('project_gebruiker')) {
            $medewerkerData = DB::table('project_gebruiker')
                ->join('users', 'project_gebruiker.user_id', '=', 'users.id')
                ->whereIn('project_gebruiker.project_id', $projectIds)
                ->select('project_gebruiker.project_id', 'users.id', 'users.naam', 'users.kleur')
                ->get()
                ->groupBy('project_id');
        } else {
            $medewerkerData = collect();
        }

        foreach ($projects as $project) {
            $project->medewerkers = ($medewerkerData->get($project->id) ?? collect())
                ->map(fn ($u) => ['id' => $u->id, 'naam' => $u->naam, 'kleur' => $u->kleur])
                ->values();
        }

        return response()->json($projects);
    }

    public function store(Request $request)
    {
        return DB::transaction(fn () => $this->storeAssignedProject($request));
    }

    private function storeAssignedProject(Request $request)
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

        app(AssignmentNotifications::class)->sync($project, $request, 'project');

        $project->medewerkers = $this->getMedewerkers($project->id);

        return response()->json($project);
    }

    public function update(Request $request, string $id)
    {
        return DB::transaction(fn () => $this->updateAssignedProject($request, $id));
    }

    private function updateAssignedProject(Request $request, string $id)
    {
        $project = Project::lockForUpdate()->findOrFail($id);
        $project->update([
            'naam' => $request->naam,
            'beschrijving' => $request->beschrijving,
            'kleur' => $request->kleur,
            'status' => $request->status,
            'prioriteit' => $request->prioriteit,
            'deadline' => $request->deadline,
        ]);

        app(AssignmentNotifications::class)->sync($project, $request, 'project');

        $fresh = $project->fresh();
        $fresh->medewerkers = $this->getMedewerkers($id);

        return response()->json($fresh);
    }

    public function destroy(string $id)
    {
        Project::destroy($id);

        return response()->json(['ok' => true]);
    }

    private function getMedewerkers(string $projectId): array
    {
        if (! Schema::hasTable('project_gebruiker')) {
            return [];
        }

        return DB::table('project_gebruiker')
            ->join('users', 'project_gebruiker.user_id', '=', 'users.id')
            ->where('project_gebruiker.project_id', $projectId)
            ->select('users.id', 'users.naam', 'users.kleur')
            ->get()
            ->map(fn ($u) => ['id' => $u->id, 'naam' => $u->naam, 'kleur' => $u->kleur])
            ->values()
            ->toArray();
    }
}
