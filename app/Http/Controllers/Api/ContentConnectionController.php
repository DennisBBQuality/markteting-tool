<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentConnection;
use App\Models\ContentDelivery;
use App\Services\Content\ConnectionSetupException;
use App\Services\Content\ContentStorage;
use App\Services\Content\SocialAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContentConnectionController extends Controller
{
    public function initialize(Request $request, ContentStorage $storage)
    {
        $request->validate(['confirmed' => 'accepted']);
        try {
            $exit = Artisan::call('pitboard:upgrade-content-storage');
        } catch (\Throwable) {
            $exit = 1;
        }
        abort_if($exit !== 0 || ! $storage->ready(), 503, 'De contentopslag kon niet veilig worden voorbereid. Laat de bestaande structuur controleren; er zijn geen tabellen vervangen.');

        return $this->index();
    }

    public function index()
    {
        if (! app(ContentStorage::class)->ready()) {
            return response()->json(['storage_ready' => false, 'connections' => [], 'live_allowed' => false, 'tiktok' => 'planning_only'])->header('Cache-Control', 'no-store');
        }

        return ['storage_ready' => true, 'connections' => ContentConnection::all()->map(fn ($c) => [
            'provider' => $c->provider, 'client_id' => $c->client_id, 'has_secret' => (bool) $c->client_secret, 'api_version' => $c->api_version,
            'enabled' => $c->enabled, 'verified_at' => $c->verified_at, 'scheduler_seen_at' => $c->scheduler_seen_at,
            'selected_account' => $c->selected_account, 'redirect_uri' => route('content.oauth.callback', ['provider' => $c->provider]),
            'accounts' => array_map(fn ($a) => ['id' => $a['id'], 'name' => $a['name'], 'instagram' => $a['instagram_business_account']['username'] ?? null], $c->accounts ?? []),
        ]), 'live_allowed' => app()->environment('production'), 'tiktok' => 'planning_only'];
    }

    private function connection(string $provider): ContentConnection
    {
        abort_unless(in_array($provider, ['meta', 'youtube']), 404);

        return ContentConnection::firstOrCreate(['provider' => $provider]);
    }

    public function save(Request $r, string $provider)
    {
        $data = $r->validate(['client_id' => 'required|string|max:255', 'client_secret' => 'nullable|string|max:4000', 'api_version' => 'nullable|regex:/^v[0-9]+\.0$/']);

        return DB::transaction(function () use ($provider, $data) {
            $this->connection($provider);
            $c = ContentConnection::where('provider', $provider)->lockForUpdate()->firstOrFail();
            abort_if(ContentDelivery::where('connection_id', $c->id)->whereIn('status', ['scheduled', 'submitting', 'processing', 'unknown'])->exists(), 409, 'Annuleer of controleer lopende publicaties voordat je de app verandert.');
            abort_if($provider === 'meta' && empty($data['api_version']), 422, 'Vul de Graph API-versie in die bij de Meta-app staat.');
            $c->fill(['client_id' => $data['client_id'], 'api_version' => $data['api_version'] ?? null, 'enabled' => false, 'verified_at' => null,
                'access_token' => null, 'refresh_token' => null, 'accounts' => null, 'selected_account' => null]);
            if (! empty($data['client_secret'])) {
                $c->client_secret = $data['client_secret'];
            }
            abort_unless($c->client_secret, 422, 'Vul het appgeheim in.');
            $c->save();

            return $this->index();
        });
    }

    public function start(Request $r, string $provider)
    {
        $c = $this->connection($provider);
        abort_unless($c->client_id && $c->client_secret, 422, 'Bewaar eerst de appgegevens.');
        $state = Str::random(64);
        $redirect = route('content.oauth.callback', ['provider' => $provider]);
        $r->session()->put('content_oauth_'.$provider, ['state' => hash('sha256', $state), 'expires' => time() + 600, 'redirect' => $redirect, 'user' => $r->session()->get('userId')]);
        $params = ['client_id' => $c->client_id, 'redirect_uri' => $redirect, 'response_type' => 'code', 'state' => $state];
        if ($provider === 'youtube') {
            $url = 'https://accounts.google.com/o/oauth2/v2/auth';
            $params += ['scope' => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly', 'access_type' => 'offline', 'prompt' => 'consent'];
        } else {
            $url = 'https://www.facebook.com/'.$c->api_version.'/dialog/oauth';
            $params += ['scope' => 'pages_show_list,pages_read_engagement,pages_manage_posts,instagram_basic,instagram_content_publish'];
        }

        return ['url' => $url.'?'.http_build_query($params)];
    }

    public function callback(Request $r, string $provider, SocialAuth $auth)
    {
        $state = $r->session()->pull('content_oauth_'.$provider);
        abort_unless($state && ($state['expires'] ?? 0) > time() && ($state['user'] ?? null) === $r->session()->get('userId') && hash_equals($state['state'], hash('sha256', (string) $r->query('state'))), 403, 'Aanmelding verlopen of ongeldig. Start opnieuw vanuit Pitboard.');
        abort_unless($r->filled('code') && ! $r->filled('error'), 422, 'De aanmelding is niet afgerond.');
        try {
            DB::transaction(function () use ($provider, $auth, $r, $state) {
                $c = ContentConnection::where('provider', $provider)->lockForUpdate()->firstOrFail();
                $auth->exchange($c, (string) $r->query('code'), $state['redirect']);
            });
        } catch (\Throwable $e) {
            $message = $e instanceof ConnectionSetupException ? $e->getMessage() : 'De koppeling kon niet worden bevestigd. Open Pitboard → Content → Kanalen en controleer de appgegevens en rechten.';

            return response($message, 422)->header('Content-Type', 'text/plain; charset=UTF-8')->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        }

        return redirect('/?content_connection=ready')->header('Referrer-Policy', 'no-referrer');
    }

    public function verify(string $provider, SocialAuth $auth)
    {
        try {
            DB::transaction(function () use ($provider, $auth) {
                $this->connection($provider);
                $auth->discover(ContentConnection::where('provider', $provider)->lockForUpdate()->firstOrFail());
            });
        } catch (\Throwable $e) {
            abort(422, $e instanceof ConnectionSetupException ? $e->getMessage() : 'De accountcontrole is niet gelukt. Meld opnieuw aan en controleer de rechten.');
        }

        return $this->index();
    }

    public function activate(Request $r, string $provider)
    {
        $data = $r->validate(['account_id' => 'required|string|max:255', 'enabled' => 'required|boolean', 'confirmed' => 'accepted']);

        return DB::transaction(function () use ($provider, $data) {
            $c = ContentConnection::where('provider', $provider)->lockForUpdate()->firstOrFail();
            if ($c->selected_account !== $data['account_id']) {
                $pending = ContentDelivery::where('connection_id', $c->id)->whereIn('status', ['scheduled', 'submitting', 'processing', 'unknown'])->get();
                abort_if($pending->contains(fn ($d) => ($d->account_snapshot['parent_id'] ?? null) !== $data['account_id']), 409, 'Lopende publicaties mogen niet naar een ander account worden omgeleid.');
            }
            abort_unless(collect($c->accounts ?? [])->contains('id', $data['account_id']), 422, 'Dit account is niet via de koppeling gecontroleerd.');
            if ($data['enabled']) {
                abort_unless(app()->environment('production'), 422, 'Echte publicatie kan alleen in de productieomgeving worden geactiveerd.');
                abort_unless($c->verified_at && $c->verified_at->gt(now()->subHour()), 422, 'Controleer de verbinding opnieuw.');
                abort_unless($c->scheduler_seen_at && $c->scheduler_seen_at->gt(now()->subMinutes(3)), 422, 'De automatische uitvoerder is nog niet recent aangetoond.');
            }
            $c->update(['selected_account' => $data['account_id'], 'enabled' => $data['enabled']]);

            return $this->index();
        });
    }
}
