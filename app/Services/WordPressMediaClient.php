<?php

namespace App\Services;

use App\Models\WordPressConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class WordPressMediaClient
{
    public function destination(): string
    {
        return app()->environment('local', 'testing') ? 'local' : 'live';
    }

    public function url(): string
    {
        return $this->destination() === 'local' ? 'https://bbquality.test' : 'https://www.bbquality.nl';
    }

    private function client(WordPressConnection $connection): PendingRequest
    {
        abort_unless($connection->destination === $this->destination(), 409, 'Deze verbinding hoort bij een andere omgeving.');
        if (! $connection->password) {
            throw ValidationException::withMessages(['password' => 'Vul eerst een WordPress-applicatiewachtwoord in.']);
        }

        // Never forward Basic credentials through redirects or disable TLS verification.
        return Http::baseUrl($this->url().'/wp-json/bbquality-connect/v2')
            ->withBasicAuth($connection->username, $connection->password)->acceptJson()
            ->connectTimeout(8)->timeout(60)->withoutRedirecting();
    }

    public function connection(WordPressConnection $connection): array
    {
        $data = $this->read($this->client($connection)->get('/connection'));
        if (($data['username'] ?? '') !== $connection->username || ($data['can_upload'] ?? false) !== true
            || ($data['protocol'] ?? null) !== 2 || rtrim($data['site_url'] ?? '', '/') !== $this->url()) {
            throw new \RuntimeException('De gebruiker, website of veilige uploadrechten komen niet overeen. Controleer de WordPress-plugin en gebruiker.');
        }

        return $data;
    }

    public function upload(WordPressConnection $connection, string $reference, string $webp, array $fields): array
    {
        // A timeout is an unknown outcome, never an instruction to create another asset.
        // Resolve the same immutable reference first on EVERY attempt (including retries).
        $previous = $this->read($this->client($connection)->get('/media', ['source_ref' => $reference]));
        $payload = [...$fields, 'source_ref' => $reference, 'source' => 'pitboard',
            'content_hash' => hash('sha256', $webp), 'expected_revision' => $previous['revision'] ?? 0];

        $result = $this->read($this->client($connection)
            ->attach('file', $webp, $fields['filename'], ['Content-Type' => 'image/webp'])
            ->post('/media', $payload));
        // Some WordPress installations return root-relative media URLs. Resolve only
        // ordinary same-site paths, never protocol-relative URLs or backslashes.
        $url = $result['url'] ?? '';
        if (is_string($url) && str_starts_with($url, '/') && ! str_starts_with($url, '//')
            && ! preg_match('/[\\\\\x00-\x20]/', $url)) {
            $result['url'] = $this->url().$url;
        }

        return $result;
    }

    private function read(Response $response): array
    {
        if (! in_array($response->status(), [200, 201], true) || ! is_array($response->json())) {
            $message = match ($response->status()) {
                401 => 'WordPress herkent de aanmelding niet. Gebruik een applicatiewachtwoord van deze gebruiker op deze website, niet het gewone inlogwachtwoord.',
                403 => 'De WordPress-gebruiker mist uploadrechten. Kies de rol Pitboard — alleen media.',
                404 => 'De veilige WordPress-koppeling (versie 2) is niet beschikbaar. Controleer de plugin.',
                409 => 'De foto wordt al verwerkt of is ondertussen gewijzigd. Controleer de status en probeer daarna opnieuw.',
                413 => 'Het bestand is groter dan WordPress toestaat (maximaal 15 MB).',
                415 => 'WordPress accepteert dit afbeeldingsbestand niet.',
                default => 'WordPress heeft de bewerking niet bevestigd. Probeer later opnieuw met dezelfde foto.',
            };
            // Do not leak remote response bodies, request headers or credentials into logs.
            throw new \RuntimeException($message);
        }

        return $response->json();
    }
}
