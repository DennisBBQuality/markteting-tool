<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DashboardPreferenceController extends Controller
{
    public function show(Request $request)
    {
        $row = DB::table('dashboard_preferences')->where('user_id', $request->session()->get('userId'))->first();

        return response()->json(['tiles' => $row ? json_decode($row->tiles, true) : null, 'revision' => $row?->revision ?? 0])
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'revision' => 'required|integer|min:0',
            'tiles' => 'required|array|size:5',
            'tiles.*' => 'required|array:id,width,height,visible',
            'tiles.*.id' => ['required', 'distinct', Rule::in(['calendar', 'tasks', 'projects', 'notes', 'trunkrs'])],
            'tiles.*.width' => ['required', 'integer', Rule::in([4, 6, 8, 12])],
            'tiles.*.height' => ['required', 'integer', Rule::in([160, 280, 420, 560])],
            'tiles.*.visible' => 'required|boolean',
        ]);

        foreach ($data['tiles'] as $tile) {
            abort_if(in_array($tile['id'], ['calendar', 'tasks'], true) && $tile['height'] < 280, 422, 'Kalender en taken hebben minimaal de compacte hoogte nodig.');
        }

        return DB::transaction(function () use ($request, $data) {
            $userId = $request->session()->get('userId');
            DB::table('dashboard_preferences')->insertOrIgnore([
                'user_id' => $userId, 'tiles' => 'null', 'revision' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('dashboard_preferences')->where('user_id', $userId)->lockForUpdate()->first();
            abort_if((int) $row->revision !== (int) $data['revision'], 409, 'Je dashboard is in een ander tabblad gewijzigd. Herlaad voordat je opnieuw aanpast.');
            $revision = $row->revision + 1;
            DB::table('dashboard_preferences')->where('user_id', $userId)->update([
                'tiles' => json_encode($data['tiles']), 'revision' => $revision, 'updated_at' => now(),
            ]);

            return response()->json(['tiles' => $data['tiles'], 'revision' => $revision]);
        });
    }
}
