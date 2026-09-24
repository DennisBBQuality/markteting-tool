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
            'tiles' => 'required|array|min:5|max:6',
            'tiles.*' => 'required|array:id,width,height,visible',
            'tiles.*.id' => ['required', 'distinct', Rule::in(['calendar', 'tasks', 'projects', 'notes', 'trunkrs', 'notifications'])],
            'tiles.*.width' => ['required', 'integer', Rule::in([4, 6, 8, 12])],
            'tiles.*.height' => ['required', 'integer', Rule::in([160, 280, 420, 560])],
            'tiles.*.visible' => 'required|boolean',
        ]);

        abort_if(array_diff(['calendar', 'tasks', 'projects', 'notes', 'trunkrs'], array_column($data['tiles'], 'id')), 422, 'De dashboardindeling mist een bestaande tegel.');
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
            // Older open tabs submit five widgets: preserve the inbox's saved position and options.
            if (count($data['tiles']) === 5) {
                $previous = json_decode($row->tiles, true) ?? [];
                $position = array_search('notifications', array_column($previous, 'id'), true);
                $notification = $position === false ? ['id' => 'notifications', 'width' => 12, 'height' => 280, 'visible' => true] : $previous[$position];
                array_splice($data['tiles'], $position === false ? 0 : $position, 0, [$notification]);
            }
            $revision = $row->revision + 1;
            DB::table('dashboard_preferences')->where('user_id', $userId)->update([
                'tiles' => json_encode($data['tiles']), 'revision' => $revision, 'updated_at' => now(),
            ]);

            return response()->json(['tiles' => $data['tiles'], 'revision' => $revision]);
        });
    }
}
