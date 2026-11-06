<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentConnection;
use App\Models\ContentPost;
use App\Services\Content\ContentPublisher;
use App\Services\Content\ContentWorkflow;
use App\Services\Content\PublicationRules;
use App\Services\Content\SocialAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContentPublicationController extends Controller
{
    public function targets()
    {
        $targets = [];
        foreach (ContentConnection::all() as $c) {
            $a = collect($c->accounts ?? [])->firstWhere('id', $c->selected_account);
            if (! $a) {
                continue;
            }
            foreach ($c->provider === 'meta' ? ['facebook', 'instagram'] : ['youtube'] as $platform) {
                $id = $platform === 'instagram' ? ($a['instagram_business_account']['id'] ?? null) : $a['id'];
                if ($id) {
                    $targets[$platform] = ['id' => $id, 'name' => $platform === 'instagram' ? ($a['instagram_business_account']['username'] ?? $a['name']) : $a['name'], 'enabled' => $c->enabled];
                }
            }
        }

        return ['targets' => $targets];
    }

    public function schedule(Request $r, string $id, ContentWorkflow $workflow, PublicationRules $rules, SocialAuth $auth)
    {
        $data = $r->validate(['revision' => 'required|integer', 'delivery_ids' => 'required|array|min:1|max:3', 'delivery_ids.*' => 'required|uuid|distinct', 'account_ids' => 'required|array:instagram,facebook,youtube', 'account_ids.*' => 'required|string|max:255', 'confirmed' => 'accepted']);
        abort_unless(app()->environment('production'), 422, 'Echte publicatie staat in deze omgeving uit. Concepten en de contentkalender blijven beschikbaar.');

        return DB::transaction(function () use ($r, $id, $data, $workflow, $rules, $auth) {
            $post = ContentPost::lockForUpdate()->findOrFail($id);
            $workflow->revision($post, $data['revision']);
            abort_unless($post->review_status === 'approved' && $post->approved_revision === $post->revision, 409, 'Laat deze exacte versie eerst goedkeuren.');
            $deliveries = $post->deliveries()->whereIn('id', $data['delivery_ids'])->lockForUpdate()->get();
            abort_unless($deliveries->count() === count($data['delivery_ids']), 422, 'Ongeldige kanaalselectie.');
            foreach ($deliveries as $d) {
                abort_unless(in_array($d->status, ['draft', 'cancelled']), 409, 'Dit kanaal is al aangeboden voor publicatie.');
                abort_unless($d->scheduled_at?->gt(now()->addMinutes(2)), 422, 'Kies een publicatietijd die minstens twee minuten in de toekomst ligt.');
                $rules->check($d);
                $c = ContentConnection::where('provider', $d->platform === 'youtube' ? 'youtube' : 'meta')->lockForUpdate()->first();
                abort_unless($c?->enabled && $c->verified_at, 422, 'Activeer eerst de gecontroleerde kanaalverbinding.');
                abort_unless($c->scheduler_seen_at?->gt(now()->subMinutes(3)), 422, 'De automatische uitvoerder is niet recent actief. Laat de serverplanning controleren.');
                $a = $auth->account($c);
                $accountId = $d->platform === 'instagram' ? ($a['instagram_business_account']['id'] ?? null) : $a['id'];
                abort_unless($accountId, 422, 'De geselecteerde Facebook-pagina heeft geen gekoppeld professioneel Instagram-account.');
                abort_unless(($data['account_ids'][$d->platform] ?? null) === $accountId, 409, 'Het publicatieaccount is veranderd. Controleer en bevestig de planning opnieuw.');
                $name = $d->platform === 'instagram' ? ($a['instagram_business_account']['username'] ?? $a['name']) : $a['name'];
                $d->update(['status' => 'scheduled', 'connection_id' => $c->id, 'account_snapshot' => ['id' => $accountId, 'parent_id' => $a['id'], 'name' => $name], 'error' => null]);
            }
            $workflow->activity($post, $r->session()->get('userId'), 'scheduled', 'Automatische publicatie bevestigd voor: '.$deliveries->pluck('platform')->join(', ').'.');

            return $post->load('deliveries', 'activities');
        });
    }

    public function cancel(Request $r, string $id, ContentWorkflow $workflow)
    {
        $data = $r->validate(['revision' => 'required|integer']);

        return DB::transaction(function () use ($r, $id, $data, $workflow) {
            $post = ContentPost::lockForUpdate()->findOrFail($id);
            $workflow->revision($post, $data['revision']);
            $deliveries = $post->deliveries()->where('status', 'scheduled')->lockForUpdate()->get();
            abort_if($deliveries->isEmpty(), 409, 'Geen wachtende planning om te annuleren. Een al gestarte overdracht wordt hiermee niet ingetrokken.');
            foreach ($deliveries as $d) {
                $d->update(['status' => 'cancelled']);
            }
            $workflow->activity($post, $r->session()->get('userId'), 'cancelled', 'Nog niet gestarte publicaties geannuleerd.');

            return $post->load('deliveries', 'activities');
        });
    }

    public function refresh(string $id, ContentPublisher $publisher)
    {
        $post = ContentPost::with('deliveries')->findOrFail($id);
        foreach ($post->deliveries as $d) {
            if (in_array($d->status, ['processing', 'unknown'])) {
                $publisher->run($d->id, false);
            }
        }

        return $post->fresh()->load('deliveries', 'activities');
    }
}
