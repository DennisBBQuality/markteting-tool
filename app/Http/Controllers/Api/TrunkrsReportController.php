<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrunkrsReport;
use App\Services\Trunkrs\TrunkrsDashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class TrunkrsReportController extends Controller
{
    public function summary(Request $request, TrunkrsDashboard $dashboard)
    {
        $data = $request->validate(['report_id' => 'nullable|uuid']);

        return response()->json($dashboard->summary($data['report_id'] ?? null))->header('Cache-Control', 'no-store');
    }

    public function index(Request $request, TrunkrsDashboard $dashboard)
    {
        $request->validate(['page' => 'nullable|integer|min:1|max:10000']);
        $reports = Schema::hasTable('trunkrs_reports')
            ? TrunkrsReport::orderByDesc('received_at')->paginate(30) : null;

        return response()->json([
            'reports' => $reports?->getCollection()->map(fn ($r) => $dashboard->report($r))->all() ?? [],
            'page' => $reports?->currentPage() ?? 1, 'last_page' => $reports?->lastPage() ?? 1,
        ])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $id, TrunkrsDashboard $dashboard)
    {
        $request->validate(['page' => 'nullable|integer|min:1|max:100']);
        abort_unless(Schema::hasTable('trunkrs_reports'), 404);
        $report = TrunkrsReport::findOrFail($id);
        $page = (int) $request->input('page', 1);

        return response()->json([
            'report' => $dashboard->report($report), 'page' => $page,
            'last_page' => max(1, (int) ceil($report->shipment_count / 100)),
            'shipments' => array_slice($report->shipments, ($page - 1) * 100, 100),
        ])->header('Cache-Control', 'no-store');
    }
}
