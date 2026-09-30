<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttachmentController extends Controller
{
    public function index(Request $request)
    {
        $query = Attachment::select('attachments.*', 'u.naam as geupload_door_naam')
            ->leftJoin('users as u', 'attachments.geupload_door', '=', 'u.id');

        if ($request->filled('project_id')) {
            $query->where('attachments.project_id', $request->project_id);
        }
        if ($request->filled('task_id')) {
            $query->where('attachments.task_id', $request->task_id);
        }
        if ($request->filled('calendar_item_id')) {
            $query->where('attachments.calendar_item_id', $request->calendar_item_id);
        }
        if ($request->filled('note_id')) {
            $query->where('attachments.note_id', $request->note_id);
        }

        return response()->json($query->orderByDesc('attachments.created_at')->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'bestand' => [
                'required',
                'file',
                'max:25600',
                'extensions:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,csv,zip',
                'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,csv,zip',
            ],
        ]);

        $file = $request->file('bestand');
        $extension = strtolower($file->getClientOriginalExtension());
        $filename = Str::uuid().'.'.$extension;
        abort_unless($file->storeAs('uploads', $filename, 'local'), 500, 'Het bestand kon niet worden opgeslagen.');

        $attachment = Attachment::create([
            'project_id' => $request->project_id,
            'task_id' => $request->task_id,
            'calendar_item_id' => $request->calendar_item_id,
            'note_id' => $request->note_id,
            'bestandsnaam' => $filename,
            'originele_naam' => $file->getClientOriginalName(),
            'mimetype' => $file->getMimeType(),
            'grootte' => $file->getSize(),
            'geupload_door' => $request->session()->get('userId'),
        ]);

        return response()->json($attachment);
    }

    public function download(string $id)
    {
        $attachment = Attachment::findOrFail($id);

        return response()->download($this->filePath($attachment), $attachment->originele_naam, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function preview(string $id)
    {
        $attachment = Attachment::findOrFail($id);
        $path = $this->filePath($attachment);
        // Inspect the stored bytes, not the client filename or database MIME label.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true), 415, 'Dit bestand heeft geen fotopreview.');

        return response()->file($path, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function filePath(Attachment $attachment): string
    {
        $filename = $attachment->bestandsnaam;
        abort_unless($filename && basename($filename) === $filename && ! str_contains($filename, '\\'), 404);
        $path = 'uploads/'.$filename;
        abort_unless(Storage::disk('local')->exists($path), 404, 'Het bestand is niet meer beschikbaar op de server.');

        return Storage::disk('local')->path($path);
    }

    public function destroy(string $id)
    {
        $attachment = Attachment::find($id);
        if ($attachment) {
            Storage::disk('local')->delete('uploads/'.$attachment->bestandsnaam);
            $attachment->delete();
        }

        return response()->json(['ok' => true]);
    }
}
