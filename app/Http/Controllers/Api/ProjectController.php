<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatChannel;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

        $medewerkerData = DB::table('project_gebruiker')
            ->join('users', 'project_gebruiker.user_id', '=', 'users.id')
            ->whereIn('project_gebruiker.project_id', $projectIds)
            ->select('project_gebruiker.project_id', 'users.id', 'users.naam', 'users.kleur')
            ->get()
            ->groupBy('project_id');

        foreach ($projects as $project) {
            $project->medewerkers = ($medewerkerData->get($project->id) ?? collect())
                ->map(fn($u) => ['id' => $u->id, 'naam' => $u->naam, 'kleur' => $u->kleur])
                ->values();
        }

        return response()->json($projects);
    }

    public function store(Request $request)
    {
        $request->validate(['naam' => 'required|string']);

        $project = Project::create([
            'naam'           => $request->naam,
            'beschrijving'   => $request->beschrijving ?? '',
            'kleur'          => $request->kleur ?? '#3B82F6',
            'prioriteit'     => $request->prioriteit ?? 'normaal',
            'deadline'       => $request->deadline,
            'aangemaakt_door'=> $request->session()->get('userId'),
        ]);

        ChatChannel::create([
            'naam'           => $request->naam,
            'type'           => 'project',
            'project_id'     => $project->id,
            'aangemaakt_door'=> $request->session()->get('userId'),
        ]);

        $this->syncMedewerkers($project->id, $request);

        $project->medewerkers = $this->getMedewerkers($project->id);
        return response()->json($project);
    }

    public function update(Request $request, string $id)
    {
        $project = Project::findOrFail($id);
        $project->update([
            'naam'        => $request->naam,
            'beschrijving'=> $request->beschrijving,
            'kleur'       => $request->kleur,
            'status'      => $request->status,
            'prioriteit'  => $request->prioriteit,
            'deadline'    => $request->deadline,
        ]);

        $this->syncMedewerkers($id, $request);

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
        return DB::table('project_gebruiker')
            ->join('users', 'project_gebruiker.user_id', '=', 'users.id')
            ->where('project_gebruiker.project_id', $projectId)
            ->select('users.id', 'users.naam', 'users.kleur')
            ->get()
            ->map(fn($u) => ['id' => $u->id, 'naam' => $u->naam, 'kleur' => $u->kleur])
            ->values()
            ->toArray();
    }

    private function syncMedewerkers(string $projectId, Request $request): void
    {
        $value = $request->medewerkers;
        $ids = match (true) {
            is_array($value) => array_filter($value),
            is_string($value) && $value !== '' => [$value],
            default => [],
        };

        DB::table('project_gebruiker')->where('project_id', $projectId)->delete();
        foreach ($ids as $userId) {
            DB::table('project_gebruiker')->insert([
                'id'         => (string) Str::uuid(),
                'project_id' => $projectId,
                'user_id'    => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
