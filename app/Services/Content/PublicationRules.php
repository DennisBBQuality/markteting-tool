<?php

namespace App\Services\Content;

use App\Models\ContentDelivery;
use App\Models\ContentMedia;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PublicationRules
{
    public function check(ContentDelivery $d): void
    {
        $error = function (string $message) use ($d) {
            throw ValidationException::withMessages(['publication' => ucfirst($d->platform).': '.$message]);
        };
        $format = $d->options['format'] ?? null;
        if ($d->platform === 'tiktok') {
            $error('alleen planning; automatische publicatie volgt later.');
        }
        if (! in_array($format, match ($d->platform) {
            'facebook' => ['photo', 'text'], 'instagram' => ['photo', 'video'], 'youtube' => ['video'], default => [],
        })) {
            $error('dit formaat wordt nog niet automatisch gepubliceerd.');
        }
        $ids = $d->media_ids ?? [];
        if (count($ids) !== ($format === 'text' ? 0 : 1)) {
            $error($format === 'text' ? 'kies geen media bij tekst.' : 'kies precies één bestand voor deze eerste versie.');
        }
        $length = mb_strlen($d->text ?? '');
        if ($length > ($d->platform === 'instagram' ? 2200 : ($d->platform === 'youtube' ? 5000 : 10000))) {
            $error('de tekst is te lang voor dit kanaal.');
        }
        if ($format === 'text' && ! trim($d->text ?? '')) {
            $error('vul een tekst in.');
        }
        // Meta's disclosure support varies by media/login route: do not silently drop this choice.
        if ($d->platform !== 'youtube' && ($d->options['synthetic'] ?? false)) {
            $error('AI-disclosure wordt voor Meta nog niet doorgegeven; publiceer dit bericht voorlopig handmatig.');
        }
        foreach ($ids as $id) {
            $m = ContentMedia::find($id);
            if (! $m || ! Storage::disk('local')->exists($m->path)) {
                $error('een geselecteerd bestand ontbreekt.');
            }
            if ($m->size > 25 * 1024 * 1024) {
                $error('het bestand is groter dan de huidige limiet van 25 MB.');
            }
            if (! hash_equals($m->sha256, hash_file('sha256', Storage::disk('local')->path($m->path)))) {
                $error('het bestand is na het uploaden veranderd. Voeg het opnieuw toe en laat de versie opnieuw goedkeuren.');
            }
            if ($format === 'video' && $m->mime !== 'video/mp4') {
                $error('kies een MP4-video.');
            }
            if ($format === 'photo' && ! in_array($m->mime, ['image/jpeg', 'image/png'])) {
                $error('kies een JPG of PNG.');
            }
            if ($d->platform === 'instagram' && $format === 'photo') {
                if ($m->mime !== 'image/jpeg') {
                    $error('gebruik voor automatische Instagram-foto’s een JPG.');
                }
                $size = @getimagesize(Storage::disk('local')->path($m->path));
                if (! $size || $size[1] < 1 || $size[0] / $size[1] < .8 || $size[0] / $size[1] > 1.91 || $m->size > 8 * 1024 * 1024) {
                    $error('kies een JPG van maximaal 8 MB met beeldverhouding 4:5 tot 1,91:1.');
                }
            }
        }
        if ($d->platform === 'youtube') {
            if (! trim($d->options['title'] ?? '') || mb_strlen($d->options['title']) > 100 || preg_match('/[<>]/', $d->options['title'])) {
                $error('vul een geldige videotitel in (maximaal 100 tekens, zonder < of >).');
            }
            if (! in_array($d->options['privacy'] ?? null, ['public', 'unlisted', 'private'])) {
                $error('kies de zichtbaarheid.');
            }
            if (! is_bool($d->options['made_for_kids'] ?? null)) {
                $error('geef aan of de video voor kinderen is gemaakt.');
            }
            if (preg_match('/[<>]/', $d->text ?? '')) {
                $error('gebruik geen < of > in de beschrijving.');
            }
        }
    }
}
