<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentPost;
use App\Services\Content\ContentWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContentController extends Controller
{
    public function index(Request $request)
    {
        $query = ContentPost::with('deliveries');
        if ($request->filled('status')) {
            $query->where('review_status', $request->string('status')->toString());
        }
        if ($request->filled('owner_id')) {
            $query->where('owner_id', $request->string('owner_id')->toString());
        }
        if ($request->filled('project_id')) {
            $query->where('project_id', $request->string('project_id')->toString());
        }
        if ($request->filled('platform')) {
            $query->whereHas('deliveries', fn ($q) => $q->where('platform', $request->string('platform')->toString()));
        }

        return $query->latest('updated_at')->paginate(100);
    }

    public function show(string $id)
    {
        return ContentPost::with('deliveries', 'activities')->findOrFail($id);
    }

    public function save(Request $request, ContentWorkflow $workflow, ?string $id = null)
    {
        $data = $request->validate([
            'revision' => ($id ? 'required' : 'nullable').'|integer|min:1',
            'title' => 'required|string|max:200', 'brief' => 'nullable|string|max:20000',
            'source_url' => 'nullable|url:http,https|max:2000',
            'owner_id' => ['required', 'uuid', Rule::exists('users', 'id')->where('actief', true)],
            'project_id' => 'nullable|uuid|exists:projects,id', 'task_id' => 'nullable|uuid|exists:tasks,id',
            'deliveries' => 'present|array|max:4', 'deliveries.*.platform' => ['required', 'distinct', Rule::in(ContentWorkflow::PLATFORMS)],
            'deliveries.*.text' => 'nullable|string|max:10000', 'deliveries.*.scheduled_at' => 'nullable|string|max:40',
            'deliveries.*.media_ids' => 'present|array|max:10', 'deliveries.*.media_ids.*' => 'uuid|distinct|exists:content_media,id',
            'deliveries.*.options' => 'present|array:title,format,privacy,made_for_kids,synthetic,commercial,allow_comments',
            'deliveries.*.options.title' => 'nullable|string|max:100',
            'deliveries.*.options.format' => 'required|in:photo,video,text',
            'deliveries.*.options.privacy' => 'nullable|in:public,private,unlisted',
            'deliveries.*.options.made_for_kids' => 'nullable|boolean',
            'deliveries.*.options.synthetic' => 'nullable|boolean',
            'deliveries.*.options.commercial' => 'nullable|boolean',
            'deliveries.*.options.allow_comments' => 'nullable|boolean',
        ]);

        return $workflow->save($data, $request->session()->get('userId'), $id);
    }

    public function review(Request $request, string $id, ContentWorkflow $workflow)
    {
        $data = $request->validate(['revision' => 'required|integer', 'action' => 'required|in:request,approve,changes', 'message' => 'nullable|string|max:5000']);
        if ($data['action'] !== 'request') {
            abort_unless(in_array($request->session()->get('rol'), ['admin', 'manager']), 403);
        }

        return DB::transaction(function () use ($data, $request, $id, $workflow) {
            $post = ContentPost::lockForUpdate()->findOrFail($id);
            $workflow->revision($post, $data['revision']);
            $workflow->editable($post);
            abort_if(! $post->deliveries()->exists(), 422, 'Voeg eerst een kanaalversie toe.');
            if ($data['action'] === 'approve') {
                abort_unless($post->review_status === 'review', 409, 'Bied deze versie eerst ter beoordeling aan.');
                $post->fill(['review_status' => 'approved', 'approved_revision' => $post->revision,
                    'approved_by' => $request->session()->get('userId'), 'approved_at' => now()]);
            } else {
                $post->fill(['review_status' => $data['action'] === 'request' ? 'review' : 'changes',
                    'approved_revision' => null, 'approved_by' => null, 'approved_at' => null]);
            }
            $post->save();
            $labels = ['request' => 'Ter beoordeling aangeboden.', 'approve' => 'Deze versie goedgekeurd.', 'changes' => 'Aanpassingen gevraagd.'];
            $workflow->activity($post, $request->session()->get('userId'), $data['action'], $labels[$data['action']].' '.($data['message'] ?? ''));

            return $post->load('deliveries', 'activities');
        });
    }

    public function comment(Request $request, string $id, ContentWorkflow $workflow)
    {
        $data = $request->validate(['message' => 'required|string|max:5000']);
        $post = ContentPost::findOrFail($id);
        $workflow->activity($post, $request->session()->get('userId'), 'comment', $data['message']);

        return $post->load('deliveries', 'activities');
    }

    public function duplicate(Request $request, string $id, ContentWorkflow $workflow)
    {
        $post = ContentPost::with('deliveries')->findOrFail($id);
        $data = $post->only('brief', 'source_url', 'project_id', 'task_id');
        $data['title'] = mb_substr($post->title, 0, 180).' (kopie)';
        $data['owner_id'] = $request->session()->get('userId');
        $data['deliveries'] = $post->deliveries->map(fn ($d) => $d->only('platform', 'text', 'media_ids', 'options'))->all();

        return $workflow->save($data, $request->session()->get('userId'));
    }
}
