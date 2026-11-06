<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentDelivery;
use App\Models\ContentMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ContentMediaController extends Controller
{
    public function index(Request $request)
    {
        if ($request->filled('ids')) {
            $data = $request->validate(['ids' => 'required|array|max:40', 'ids.*' => 'uuid']);

            return ['data' => ContentMedia::select('id', 'name', 'mime', 'size', 'created_at')->whereIn('id', $data['ids'])->get()];
        }

        return ContentMedia::select('id', 'name', 'mime', 'size', 'created_at')->latest()->paginate(100);
    }

    public function shared(string $delivery, string $media)
    {
        $d = ContentDelivery::findOrFail($delivery);
        abort_unless(in_array($d->status, ['submitting', 'processing', 'unknown', 'published']) && in_array($media, $d->media_ids ?? [], true), 404);

        return $this->show($media);
    }

    public function store(Request $request)
    {
        $request->validate(['file' => 'required|file|max:25600|mimes:jpg,jpeg,png,webp,mp4']);
        $file = $request->file('file');
        $mime = $file->getMimeType();
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'video/mp4']), 422, 'Kies een JPG, PNG, WEBP of MP4.');
        if (str_starts_with($mime, 'image/')) {
            abort_unless(@getimagesize($file->getRealPath()), 422, 'De afbeelding is beschadigd.');
        }
        $path = $file->storeAs('content-media', (string) Str::uuid(), 'local');
        abort_unless($path, 500, 'Het bestand kon niet worden opgeslagen.');
        try {
            $media = ContentMedia::create(['name' => mb_substr(basename($file->getClientOriginalName()), 0, 240), 'path' => $path,
                'mime' => $mime, 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'uploaded_by' => $request->session()->get('userId')]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return response()->json($media->only('id', 'name', 'mime', 'size', 'created_at'), 201);
    }

    public function show(string $id)
    {
        $media = ContentMedia::findOrFail($id);
        abort_unless(Storage::disk('local')->exists($media->path), 404, 'Dit bestand is niet meer beschikbaar.');

        return response()->file(Storage::disk('local')->path($media->path), ['Content-Type' => $media->mime,
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
