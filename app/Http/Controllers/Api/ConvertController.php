<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

class ConvertController extends Controller
{
    public function toWebp(Request $request)
    {
        if (! $request->hasFile('bestanden')) {
            return response()->json(['error' => 'Geen bestanden geüpload'], 400);
        }

        $request->validate(['bestanden' => ['required', 'array'], 'bestanden.*' => ['required', 'file', 'max:25600']]);
        $quality = min(max((int) ($request->quality ?? 80), 1), 100);
        $results = [];

        foreach ($request->file('bestanden') as $file) {
            try {
                $baseName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $id = (string) Str::uuid();
                $outputName = $id.'.webp';

                $image = Image::read($file->getPathname());
                $encoded = $image->toWebp($quality);

                if (! Storage::disk('public')->put('converted/'.$outputName, (string) $encoded)) {
                    throw new \RuntimeException('Het bestand kon niet worden opgeslagen. Probeer opnieuw.');
                }

                $convertedSize = strlen((string) $encoded);
                $results[] = [
                    'id' => $id,
                    'origineel' => $file->getClientOriginalName(),
                    'origineel_grootte' => $file->getSize(),
                    'geconverteerd' => $baseName.'.webp',
                    'geconverteerd_grootte' => $convertedSize,
                    'breedte' => $image->width(),
                    'hoogte' => $image->height(),
                    'besparing' => round((1 - $convertedSize / $file->getSize()) * 100),
                    'download_url' => '/api/convert/download?bestand='.rawurlencode($outputName),
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'origineel' => $file->getClientOriginalName(),
                    'error' => 'Conversie mislukt: '.$e->getMessage(),
                ];
            }
        }

        return response()->json(['results' => $results]);
    }

    public function download(Request $request, ?string $filename = null)
    {
        $filename ??= $request->query('bestand');
        if (! is_string($filename) || $filename === '' || str_contains($filename, '/') || str_contains($filename, '\\') || ! str_ends_with($filename, '.webp')) {
            return response()->json(['error' => 'Bestand niet gevonden'], 404);
        }
        $path = 'converted/'.$filename;

        if (! Storage::disk('public')->exists($path)) {
            return response()->json(['error' => 'Bestand niet gevonden'], 404);
        }

        $downloadName = $request->query('naam', $filename);
        if (! is_string($downloadName)) {
            return response()->json(['error' => 'Ongeldige bestandsnaam'], 422);
        }

        return Storage::disk('public')->download($path, basename($downloadName), [
            'Content-Type' => 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
