<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarItem;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    public function index(Request $request)
    {
        $query = CalendarItem::select('calendar_items.*',
                'p.naam as project_naam', 'p.kleur as project_kleur',
                'u.naam as aangemaakt_door_naam')
            ->leftJoin('projects as p', 'calendar_items.project_id', '=', 'p.id')
            ->leftJoin('users as u', 'calendar_items.aangemaakt_door', '=', 'u.id');

        if ($request->boolean('overlap')) {
            // Existing calendar values are local wall-clock times, without a timezone cast.
            $request->validate(['start' => 'required|date_format:Y-m-d', 'end' => 'required|date_format:Y-m-d|after:start']);
            $start = $request->start;
            $end = $request->end;
            $query->whereDate('calendar_items.datum_start', '<', $end)
                ->where(function ($q) use ($start) {
                    $q->whereDate('calendar_items.datum_start', '>=', $start)
                        ->orWhereDate('calendar_items.datum_eind', '>', $start)
                        ->orWhere(function ($endQuery) use ($start) {
                            $endQuery->whereDate('calendar_items.datum_eind', $start)
                                ->whereTime('calendar_items.datum_eind', '>', '00:00:00');
                        });
                });
        } else {
            if ($request->filled('start')) $query->where('calendar_items.datum_start', '>=', $request->start);
            if ($request->filled('end')) $query->where('calendar_items.datum_start', '<=', $request->end);
        }
        if ($request->filled('type')) $query->where('calendar_items.type', $request->type);
        if ($request->filled('project_id')) $query->where('calendar_items.project_id', $request->project_id);

        return response()->json($query->orderBy('calendar_items.datum_start')->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'titel' => 'required|string',
            'datum_start' => 'required',
        ]);

        $item = CalendarItem::create([
            'project_id' => $request->project_id,
            'titel' => $request->titel,
            'beschrijving' => $request->beschrijving ?? '',
            'type' => $request->type ?? 'content',
            'datum_start' => $request->datum_start,
            'datum_eind' => $request->datum_eind,
            'kleur' => $request->kleur,
            'link' => $request->link,
            'aangemaakt_door' => $request->session()->get('userId'),
        ]);

        return response()->json($item);
    }

    public function update(Request $request, string $id)
    {
        $item = CalendarItem::findOrFail($id);
        $item->update([
            'project_id' => $request->project_id,
            'titel' => $request->titel,
            'beschrijving' => $request->beschrijving,
            'type' => $request->type,
            'datum_start' => $request->datum_start,
            'datum_eind' => $request->datum_eind,
            'kleur' => $request->kleur,
            'link' => $request->link,
        ]);

        return response()->json($item->fresh());
    }

    public function destroy(string $id)
    {
        CalendarItem::destroy($id);
        return response()->json(['ok' => true]);
    }
}
