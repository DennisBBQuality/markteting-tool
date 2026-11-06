<?php

namespace App\Services\Content;

use App\Models\ContentConnection;
use App\Models\ContentDelivery;
use App\Models\ContentMedia;
use App\Models\ContentPost;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;

class ContentPublisher
{
    public function __construct(private SocialAuth $auth, private PublicationRules $rules, private ContentWorkflow $workflow) {}

    public function run(string $id, bool $advance = true): void
    {
        // This check is deliberately also inside the service: no UI/CLI bypass in local environments.
        if (! app()->environment('production')) {
            return;
        }
        Cache::lock('content-delivery-'.$id, 600)->get(function () use ($id, $advance) {
            $d = ContentDelivery::find($id);
            if (! $d || ! in_array($d->status, ['scheduled', 'processing', 'unknown', 'submitting'])) {
                return;
            }
            if ($d->status === 'submitting') {
                if ($d->submitted_at?->lt(now()->subMinutes(10))) {
                    $this->state($d, 'unknown', 'De overdracht is onderbroken. Controleer het platform; er wordt niet opnieuw verzonden.');
                }

                return;
            }
            if ($d->checked_at?->gt(now()->subSeconds(45))) {
                return;
            }
            if ($d->status === 'scheduled' && (! $advance || $d->scheduled_at?->isFuture())) {
                return;
            }
            $c = ContentConnection::find($d->connection_id);
            if (! $c?->enabled) {
                return;
            }
            try {
                $a = $this->auth->account($c);
                $account = $d->account_snapshot;
                $target = $d->platform === 'instagram' ? ($a['instagram_business_account']['id'] ?? null) : $a['id'];
                if (($account['id'] ?? null) !== $target || ($account['parent_id'] ?? null) !== $a['id']) {
                    throw new RuntimeException('Account gewijzigd.');
                }
                $token = $c->provider === 'youtube' ? $this->auth->token($c) : $a['access_token'];
                if ($d->status === 'scheduled') {
                    if ($d->scheduled_at->lt(now()->subMinutes(15))) {
                        $this->state($d, 'failed', 'Het publicatiemoment is meer dan 15 minuten gemist. Maak bewust een nieuwe planning via Dupliceren.');

                        return;
                    }
                    $this->rules->check($d);
                    if ($d->platform === 'youtube') {
                        $channels = $this->auth->json($this->auth->http($token)->get('https://www.googleapis.com/youtube/v3/channels', ['part' => 'id', 'mine' => 'true']));
                        if (! collect($channels['items'] ?? [])->contains('id', $target)) {
                            throw new RuntimeException('YouTube-account gewijzigd.');
                        }
                    }
                    // Lock in the same order as save/cancel: source first, delivery second.
                    $claimed = DB::transaction(function () use ($d) {
                        $p = ContentPost::lockForUpdate()->findOrFail($d->post_id);
                        $fresh = ContentDelivery::lockForUpdate()->findOrFail($d->id);
                        if ($fresh->status !== 'scheduled' || ! ContentConnection::whereKey($fresh->connection_id)->lockForUpdate()->first()?->enabled) {
                            return false;
                        }
                        if ($p->review_status !== 'approved' || $p->approved_revision !== $p->revision) {
                            throw new RuntimeException('Goedkeuring vervallen.');
                        }
                        $fresh->update(['status' => 'submitting', 'submitted_at' => now(), 'checked_at' => now(), 'error' => null]);

                        return true;
                    });
                    if (! $claimed) {
                        return;
                    }
                    $d->refresh();
                    $this->start($d, $c, $token);
                } else {
                    $d->update(['checked_at' => now()]);
                    $this->poll($d, $c, $token, $advance);
                }
            } catch (\Throwable) {
                // Do not log exception text: network exceptions may contain OAuth tokens or signed media URLs.
                $d->refresh();
                if ($d->status === 'submitting') {
                    $this->state($d, 'unknown', 'Geen zekere bevestiging ontvangen. Controleer de platformstatus; automatisch opnieuw verzenden is geblokkeerd.');
                } elseif ($d->status === 'scheduled') {
                    $this->state($d, 'failed', 'Controle vóór verzending mislukt. Controleer bestand, account en rechten; er is niets verzonden.');
                } else {
                    $d->update(['error' => 'De platformstatus kon niet worden gecontroleerd. Probeer later opnieuw; er wordt niets opnieuw verstuurd.', 'checked_at' => now()]);
                }
            }
        });
    }

    private function mediaUrl(ContentDelivery $d): string
    {
        $url = URL::temporarySignedRoute('content.media.share', now()->addHours(2), ['delivery' => $d->id, 'media' => $d->media_ids[0]]);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('Publicatiemedia vereisen HTTPS.');
        }

        return $url;
    }

    private function remoteId(mixed $id): string
    {
        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,200}$/', $id)) {
            throw new RuntimeException('Geen geldig platformnummer ontvangen.');
        }

        return $id;
    }

    private function start(ContentDelivery $d, ContentConnection $c, string $token): void
    {
        if ($d->platform === 'youtube') {
            $m = ContentMedia::findOrFail($d->media_ids[0]);
            $response = $this->auth->http($token)->withHeaders(['X-Upload-Content-Type' => $m->mime, 'X-Upload-Content-Length' => (string) $m->size])
                ->post('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', [
                    'snippet' => ['title' => $d->options['title'], 'description' => $d->text ?? '', 'categoryId' => '26'],
                    'status' => ['privacyStatus' => $d->options['privacy'], 'selfDeclaredMadeForKids' => $d->options['made_for_kids'], 'containsSyntheticMedia' => (bool) ($d->options['synthetic'] ?? false)],
                ]);
            if (! $response->successful()) {
                throw new RuntimeException('Uploadsessie niet bevestigd.');
            }
            $uri = $response->header('Location');
            $this->checkUploadUrl($uri);
            $d->update(['upload_session_url' => $uri]);
            $stream = Storage::disk('local')->readStream($m->path);
            if (! is_resource($stream)) {
                throw new RuntimeException('Bestand niet leesbaar.');
            }
            try {
                $data = $this->auth->json($this->auth->http($token)->timeout(180)->withHeaders(['Content-Length' => (string) $m->size])->withBody($stream, $m->mime)->put($uri));
            } finally {
                fclose($stream);
            }
            $d->update(['provider_request_id' => $this->remoteId($data['id'] ?? null), 'status' => 'processing', 'checked_at' => now()]);

            return;
        }
        $graph = $this->auth->graph($c);
        $target = $this->remoteId($d->account_snapshot['id']);
        $format = $d->options['format'];
        if ($d->platform === 'facebook') {
            $path = $format === 'photo' ? 'photos' : 'feed';
            $payload = $format === 'photo' ? ['url' => $this->mediaUrl($d), 'caption' => $d->text ?? '', 'published' => true] : ['message' => $d->text, 'published' => true];
            $data = $this->auth->json($this->auth->http($token)->post($graph.'/'.$target.'/'.$path, $payload));
            $d->update(['provider_request_id' => $this->remoteId($format === 'photo' ? ($data['post_id'] ?? null) : ($data['id'] ?? null)), 'status' => 'processing', 'checked_at' => now()]);
        } else {
            $payload = ['caption' => $d->text ?? ''];
            if ($format === 'video') {
                $payload += ['video_url' => $this->mediaUrl($d), 'media_type' => 'REELS'];
            } else {
                $payload['image_url'] = $this->mediaUrl($d);
            }
            $data = $this->auth->json($this->auth->http($token)->post($graph.'/'.$target.'/media', $payload));
            $d->update(['provider_job_id' => $this->remoteId($data['id'] ?? null), 'status' => 'processing', 'checked_at' => now()]);
        }
    }

    private function checkUploadUrl(?string $url): void
    {
        $parts = parse_url($url ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'www.googleapis.com' || ($parts['path'] ?? '') !== '/upload/youtube/v3/videos' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
            throw new RuntimeException('Ongeldig uploadadres.');
        }
    }

    private function poll(ContentDelivery $d, ContentConnection $c, string $token, bool $advance): void
    {
        if ($d->platform === 'youtube') {
            if (! $d->provider_request_id && $d->upload_session_url) {
                // This empty PUT only queries an existing session; it never starts/restarts an upload.
                $this->checkUploadUrl($d->upload_session_url);
                $m = ContentMedia::findOrFail($d->media_ids[0]);
                $response = $this->auth->http($token)->withHeaders(['Content-Length' => '0', 'Content-Range' => 'bytes */'.$m->size])->withBody('', 'application/octet-stream')->put($d->upload_session_url);
                if ($response->status() === 308) {
                    $this->state($d, 'unknown', 'YouTube heeft een onvolledige upload. Er wordt niet automatisch opnieuw begonnen.');

                    return;
                }
                $data = $this->auth->json($response);
                $d->update(['provider_request_id' => $this->remoteId($data['id'] ?? null)]);
            }
            if (! $d->provider_request_id) {
                return;
            }
            $data = $this->auth->json($this->auth->http($token)->get('https://www.googleapis.com/youtube/v3/videos', ['part' => 'status,processingDetails,snippet', 'id' => $d->provider_request_id]));
            $video = collect($data['items'] ?? [])->firstWhere('id', $d->provider_request_id);
            if (! $video) {
                throw new RuntimeException('Video nog niet gevonden.');
            }
            if (($video['snippet']['channelId'] ?? null) !== $d->account_snapshot['id']) {
                throw new RuntimeException('Onverwacht kanaal.');
            }
            $upload = $video['status']['uploadStatus'] ?? '';
            $processing = $video['processingDetails']['processingStatus'] ?? '';
            if (in_array($upload, ['failed', 'rejected', 'deleted']) || in_array($processing, ['failed', 'terminated'])) {
                $this->state($d, 'failed', 'YouTube heeft de verwerking geweigerd. Controleer YouTube Studio.');

                return;
            }
            if ($upload === 'processed' && $processing === 'succeeded') {
                if (($video['status']['privacyStatus'] ?? null) !== $d->options['privacy']) {
                    $this->state($d, 'unknown', 'YouTube meldt een andere zichtbaarheid dan goedgekeurd. Controleer YouTube Studio.');

                    return;
                }
                $this->published($d, 'https://www.youtube.com/watch?v='.$d->provider_request_id);
            }

            return;
        }
        $graph = $this->auth->graph($c);
        if ($d->platform === 'instagram' && ! $d->provider_request_id && $d->provider_job_id) {
            $data = $this->auth->json($this->auth->http($token)->get($graph.'/'.$this->remoteId($d->provider_job_id), ['fields' => 'status_code']));
            $state = $data['status_code'] ?? '';
            if ($state === 'PUBLISHED') {
                $this->published($d, null);

                return;
            }
            if (in_array($state, ['ERROR', 'EXPIRED'])) {
                $this->state($d, 'failed', 'Instagram heeft de media niet verwerkt of de container is verlopen.');

                return;
            }
            // Only a live scheduled job may advance. Unknown outcomes and the refresh button are read-only.
            if ($state === 'FINISHED' && $advance && $d->status === 'processing') {
                $d->update(['status' => 'submitting', 'submitted_at' => now()]);
                $data = $this->auth->json($this->auth->http($token)->post($graph.'/'.$this->remoteId($d->account_snapshot['id']).'/media_publish', ['creation_id' => $d->provider_job_id]));
                $d->update(['provider_request_id' => $this->remoteId($data['id'] ?? null), 'status' => 'processing']);
            } elseif ($d->submitted_at?->lt(now()->subMinutes(10)) && $state === 'IN_PROGRESS') {
                $this->state($d, 'unknown', 'Instagram verwerkt langer dan verwacht. Controleer de status; verdere automatische publicatie is gestopt.');
            }
        }
        if (! $d->provider_request_id) {
            return;
        }
        $fields = $d->platform === 'facebook' ? 'id,is_published,permalink_url' : 'id,permalink';
        $data = $this->auth->json($this->auth->http($token)->get($graph.'/'.$this->remoteId($d->provider_request_id), ['fields' => $fields]));
        if (($data['id'] ?? null) !== $d->provider_request_id) {
            throw new RuntimeException('Onverwacht resultaat.');
        }
        if ($d->platform === 'facebook' && ($data['is_published'] ?? false) !== true) {
            return;
        }
        $link = $data[$d->platform === 'facebook' ? 'permalink_url' : 'permalink'] ?? null;
        $host = parse_url($link ?? '', PHP_URL_HOST);
        if (! $link || parse_url($link, PHP_URL_SCHEME) !== 'https' || ! in_array($host, ['www.facebook.com', 'facebook.com', 'www.instagram.com', 'instagram.com'])) {
            throw new RuntimeException('Geen geldige resultaatlink.');
        }
        $this->published($d, $link);
    }

    private function published(ContentDelivery $d, ?string $link): void
    {
        $d->published_url = $link;
        $this->state($d, 'published', null);
    }

    private function state(ContentDelivery $d, string $status, ?string $error): void
    {
        $d->fill(['status' => $status, 'error' => $error, 'checked_at' => now()])->save();
        $this->workflow->activity($d->post, null, 'publication', ucfirst($d->platform).': '.($status === 'published' ? 'plaatsing bevestigd door het platform.' : ($error ?? $status)));
    }
}
