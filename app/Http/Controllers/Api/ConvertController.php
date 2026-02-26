<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ConvertController extends Controller
{
    public function toWebp(Request $request)
    {
        if (!$request->hasFile('bestanden')) {
            return response()->json(['error' => 'Geen bestanden geüpload'], 400);
        }

        $quality = min(max((int) ($request->quality ?? 80), 1), 100);
        $results = [];

        foreach ($request->file('bestanden') as $file) {
            try {
                $baseName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $outputName = $baseName . '-' . time() . '.webp';

                $image = \Intervention\Image\Laravel\Facades\Image::read($file->getPathname());
                $encoded = $image->toWebp($quality);

                Storage::disk('public')->put('converted/' . $outputName, (string) $encoded);

                $convertedSize = strlen((string) $encoded);
                $results[] = [
                    'id' => (string) Str::uuid(),
                    'origineel' => $file->getClientOriginalName(),
                    'origineel_grootte' => $file->getSize(),
                    'geconverteerd' => $outputName,
                    'geconverteerd_grootte' => $convertedSize,
                    'breedte' => $image->width(),
                    'hoogte' => $image->height(),
                    'besparing' => round((1 - $convertedSize / $file->getSize()) * 100),
                    'download_url' => '/api/convert/download/' . $outputName,
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'origineel' => $file->getClientOriginalName(),
                    'error' => 'Conversie mislukt: ' . $e->getMessage(),
                ];
            }
        }

        return response()->json(['results' => $results]);
    }

    public function download(Request $request, string $filename)
    {
        $filename = basename($filename);
        $path = 'converted/' . $filename;

        if (!Storage::disk('public')->exists($path)) {
            return response()->json(['error' => 'Bestand niet gevonden'], 404);
        }

        $downloadName = $request->query('naam', $filename);

        return Storage::disk('public')->download($path, basename($downloadName), [
            'Content-Type' => 'image/webp',
        ]);
    }
}
