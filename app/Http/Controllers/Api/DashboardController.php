<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function stats()
    {
        $today = now()->toDateString();
        $weekEnd = now()->addDays(7)->toDateString();

        return response()->json([
            'totaal_projecten' => DB::table('projects')->where('status', '!=', 'gearchiveerd')->count(),
            'actieve_taken' => DB::table('tasks')->where('status', '!=', 'klaar')->count(),
            'taken_vandaag' => DB::table('tasks')->where('deadline', $today)->where('status', '!=', 'klaar')->count(),
            'taken_deze_week' => DB::table('tasks')->whereBetween('deadline', [$today, $weekEnd])->where('status', '!=', 'klaar')->count(),
            'taken_verlopen' => DB::table('tasks')->where('deadline', '<', $today)->where('status', '!=', 'klaar')->count(),
            'kalender_vandaag' => DB::table('calendar_items')->whereDate('datum_start', $today)->count(),
        ]);
    }
}
