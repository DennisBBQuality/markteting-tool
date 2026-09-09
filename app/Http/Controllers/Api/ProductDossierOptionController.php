<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductDossierOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductDossierOptionController extends Controller
{
    public function index(): JsonResponse
    {
        $grouped = ProductDossierOption::query()
            ->orderBy('type')
            ->orderBy('position')
            ->orderBy('label')
            ->get()
            ->groupBy('type');

        return response()->json([
            'categories' => $grouped->get('category', collect())->values(),
            'cuts' => $grouped->get('cut', collect())->values(),
            'selections' => $grouped->get('selection', collect())->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['category', 'cut', 'selection'])],
            'label' => ['required', 'string', 'min:2', 'max:160'],
        ]);
        $label = trim($validated['label']);
        $exists = ProductDossierOption::query()
            ->where('type', $validated['type'])
            ->whereRaw('LOWER(label) = ?', [mb_strtolower($label)])
            ->exists();
        if ($exists) {
            return response()->json(['error' => 'Deze keuze bestaat al in de lijst.'], 422);
        }

        $option = ProductDossierOption::create([
            'type' => $validated['type'],
            'label' => $label,
            'position' => (int) ProductDossierOption::where('type', $validated['type'])->max('position') + 1,
        ]);

        return response()->json($option, 201);
    }

    public function destroy(ProductDossierOption $productDossierOption): JsonResponse
    {
        $productDossierOption->delete();

        return response()->json(['ok' => true]);
    }
}
