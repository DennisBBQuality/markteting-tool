<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    public function index(Request $request)
    {
        $query = Attachment::select('attachments.*', 'u.naam as geupload_door_naam')
            ->leftJoin('users as u', 'attachments.geupload_door', '=', 'u.id');

        if ($request->filled('project_id')) $query->where('attachments.project_id', $request->project_id);
        if ($request->filled('task_id')) $query->where('attachments.task_id', $request->task_id);
        if ($request->filled('calendar_item_id')) $query->where('attachments.calendar_item_id', $request->calendar_item_id);
        if ($request->filled('note_id')) $query->where('attachments.note_id', $request->note_id);

        return response()->json($query->orderByDesc('attachments.created_at')->get());
    }

    public function store(Request $request)
    {
        $request->validate(['bestand' => 'required|file|max:10240']);

        $file = $request->file('bestand');
        $filename = \Str::uuid() . '.' . $file->getClientOriginalExtension();
        $file->storeAs('uploads', $filename, 'public');

        $attachment = Attachment::create([
            'project_id' => $request->project_id,
            'task_id' => $request->task_id,
            'calendar_item_id' => $request->calendar_item_id,
            'note_id' => $request->note_id,
            'bestandsnaam' => $filename,
            'originele_naam' => $file->getClientOriginalName(),
            'mimetype' => $file->getClientMimeType(),
            'grootte' => $file->getSize(),
            'geupload_door' => $request->session()->get('userId'),
        ]);

        return response()->json($attachment);
    }

    public function destroy(string $id)
    {
        $attachment = Attachment::find($id);
        if ($attachment) {
            Storage::disk('public')->delete('uploads/' . $attachment->bestandsnaam);
            $attachment->delete();
        }
        return response()->json(['ok' => true]);
    }
}
