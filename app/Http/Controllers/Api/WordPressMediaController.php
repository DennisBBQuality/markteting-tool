<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductImageAsset;
use App\Models\ProductImageRequest;
use App\Models\WordPressConnection;
use App\Models\WordPressMediaTransfer;
use App\Services\ProductImageDelivery;
use App\Services\ProductImageSeo;
use App\Services\WordPressMediaClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WordPressMediaController extends Controller
{
    public function __construct(private WordPressMediaClient $client) {}

    private function connection(): ?WordPressConnection
    {
        return Schema::hasTable('wordpress_connections')
            ? WordPressConnection::where('destination', $this->client->destination())->first() : null;
    }

    public function settings(): array
    {
        $connection = $this->connection();

        return ['available' => Schema::hasTable('wordpress_connections'), 'destination' => $this->client->destination(),
            'url' => $this->client->url(), 'username' => $connection?->username ?? 'pitboard',
            'configured' => (bool) $connection?->password, 'enabled' => (bool) $connection?->enabled,
            'tested_at' => $connection?->tested_at?->toIso8601String()];
    }

    public function saveSettings(Request $request): array
    {
        abort_unless(Schema::hasTable('wordpress_connections'), 503, 'De database-uitbreiding is nog niet toegepast.');
        $data = $request->validate(['username' => ['required', 'string', 'max:60', 'regex:/^[a-zA-Z0-9_.@-]+$/'],
            'password' => ['nullable', 'string', 'max:200'], 'enabled' => ['required', 'boolean'],
            'confirm_public' => ['required', 'accepted']]);
        if (! empty($data['password']) && ! preg_match('/^[A-Za-z0-9]{24}$/D', preg_replace('/\s+/', '', $data['password']))) {
            throw ValidationException::withMessages(['password' => 'Gebruik het WordPress-applicatiewachtwoord van 24 letters/cijfers, niet het gewone inlogwachtwoord. Spaties mogen blijven staan.']);
        }
        DB::transaction(function () use ($data) {
            $connection = WordPressConnection::firstOrCreate(['destination' => $this->client->destination()]);
            $connection = WordPressConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $changed = $connection->username !== $data['username'] || ! empty($data['password']);
            $connection->username = $data['username'];
            if (! empty($data['password'])) {
                $connection->password = preg_replace('/\s+/', '', $data['password']);
            }
            if ($changed) {
                $connection->tested_at = null;
                $connection->revision++;
            }
            // Credentials must pass the read-only test before upload can be enabled.
            $connection->enabled = ! $changed && $connection->tested_at && $data['enabled'];
            $connection->save();
        });

        return $this->settings();
    }

    public function testConnection()
    {
        $connection = $this->connection();
        abort_unless($connection?->password, 422, 'Sla eerst het applicatiewachtwoord op.');
        try {
            $data = $this->client->connection($connection);
            WordPressConnection::whereKey($connection->id)->where('revision', $connection->revision)->update(['tested_at' => now()]);

            return response()->json(['message' => 'Verbinding en uploadrechten gecontroleerd. Er is niets geüpload.', 'username' => $data['username']]);
        } catch (\Throwable $e) {
            WordPressConnection::whereKey($connection->id)->where('revision', $connection->revision)
                ->update(['tested_at' => null, 'enabled' => false]);

            return response()->json(['error' => $this->safeError($e)], 422);
        }
    }

    public function disconnect(): array
    {
        $connection = $this->connection();
        if ($connection) {
            $connection->update(['password' => null, 'enabled' => false, 'tested_at' => null, 'revision' => $connection->revision + 1]);
        }

        return $this->settings();
    }

    private function owned(Request $request, ProductImageAsset $asset): ProductImageRequest
    {
        $parent = ProductImageRequest::findOrFail($asset->product_image_request_id);
        abort_unless($parent->user_id === $request->session()->get('userId'), 404);

        return $parent;
    }

    private function fingerprint(ProductImageAsset $asset): string
    {
        $metadata = app(ProductImageSeo::class)->record($asset);

        return hash('sha256', json_encode([$asset->id, $asset->version, hash('sha256', $asset->contents_base64),
            $metadata?->revision, $metadata?->fields], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function status(Request $request, ProductImageAsset $asset): array
    {
        $this->owned($request, $asset);
        $connection = $this->connection();
        $transfer = $connection ? WordPressMediaTransfer::where(['connection_id' => $connection->id,
            'asset_id' => $asset->id, 'image_version' => $asset->version])->first() : null;
        $fingerprint = $this->fingerprint($asset);

        return ['enabled' => (bool) $connection?->enabled, 'destination' => $this->client->destination(),
            'fingerprint' => $fingerprint, 'version' => $asset->version,
            'ready' => app(ProductImageSeo::class)->payload($asset)['ready'],
            'approved' => $transfer?->approved_fingerprint === $fingerprint,
            'uploaded' => $transfer?->uploaded_fingerprint === $fingerprint,
            'attachment_id' => $transfer?->attachment_id, 'url' => $transfer?->remote_url,
            'status' => $transfer?->status ?? 'idle',
            'message' => $transfer?->status === 'uploaded' && $transfer->uploaded_fingerprint !== $fingerprint
                ? 'De huidige foto of SEO wijkt af van de verzonden versie. Controleer en keur opnieuw goed voordat je bijwerkt.'
                : $transfer?->message];
    }

    public function approve(Request $request, ProductImageAsset $asset): array
    {
        $this->owned($request, $asset);
        $data = $request->validate(['fingerprint' => 'required|string|size:64', 'approved' => 'required|boolean']);
        $connection = $this->connection();
        abort_unless($connection?->enabled, 409, 'De WordPress-koppeling is nog niet actief.');
        DB::transaction(function () use ($asset, $data, $connection, $request) {
            ProductImageRequest::whereKey($asset->product_image_request_id)->lockForUpdate()->firstOrFail();
            $asset = ProductImageAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            abort_unless($data['fingerprint'] === $this->fingerprint($asset), 409, 'De foto of SEO is gewijzigd. Controleer de nieuwe versie.');
            abort_unless(app(ProductImageSeo::class)->payload($asset)['ready'], 422, 'Rond eerst de SEO af.');
            WordPressMediaTransfer::updateOrCreate(['connection_id' => $connection->id, 'asset_id' => $asset->id,
                'image_version' => $asset->version], ['approved_fingerprint' => $data['approved'] ? $data['fingerprint'] : null,
                    'approved_by' => $request->session()->get('userId'), 'approved_at' => $data['approved'] ? now() : null]);
        });

        return $this->status($request, $asset->fresh());
    }

    public function upload(Request $request, ProductImageAsset $asset)
    {
        $this->owned($request, $asset);
        $request->validate(['fingerprint' => 'required|string|size:64']);
        $connection = $this->connection();
        abort_unless($connection?->enabled && $connection?->tested_at, 409, 'Activeer eerst de geteste WordPress-koppeling.');
        [$transfer, $snapshot, $fields, $fingerprint, $claim] = DB::transaction(function () use ($asset, $connection, $request) {
            ProductImageRequest::whereKey($asset->product_image_request_id)->lockForUpdate()->firstOrFail();
            $snapshot = ProductImageAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $fingerprint = $this->fingerprint($snapshot);
            abort_unless($request->input('fingerprint') === $fingerprint && app(ProductImageSeo::class)->payload($snapshot)['ready'], 409, 'Controleer eerst de actuele foto en SEO.');
            $transfer = WordPressMediaTransfer::where(['connection_id' => $connection->id, 'asset_id' => $snapshot->id,
                'image_version' => $snapshot->version])->lockForUpdate()->first();
            abort_unless($transfer && $transfer->approved_fingerprint === $fingerprint, 422, 'Keur deze foto en SEO eerst goed voor de mediatheek.');
            abort_if($transfer->status === 'uploading' && $transfer->claimed_at?->gt(now()->subMinutes(3)), 409, 'Deze foto wordt al verstuurd.');
            $claim = (string) Str::uuid();
            $transfer->update(['status' => 'uploading', 'claim' => $claim, 'claimed_at' => now(), 'message' => null]);
            $fields = app(ProductImageSeo::class)->record($snapshot)->fields;

            return [$transfer, $snapshot, array_intersect_key($fields, array_flip(ProductImageSeo::FIELDS)), $fingerprint, $claim];
        });
        try {
            $webp = app(ProductImageDelivery::class)->webp(base64_decode($snapshot->contents_base64, true));
            if (strlen($webp) > 15 * 1024 * 1024) {
                throw new \RuntimeException('Het bestand is groter dan WordPress toestaat (maximaal 15 MB).');
            }
            $reference = 'pitboard:'.$connection->id.':'.$snapshot->product_image_request_id.':'.$snapshot->id.':v'.$snapshot->version;
            $result = $this->client->upload($connection, $reference, $webp, $fields);
            $url = $result['url'] ?? '';
            abort_unless(is_int($result['attachment_id'] ?? null) && $result['attachment_id'] > 0
                && str_starts_with($url, $this->client->url().'/'), 502);
            WordPressMediaTransfer::whereKey($transfer->id)->where('claim', $claim)->update([
                'status' => 'uploaded', 'uploaded_fingerprint' => $fingerprint, 'attachment_id' => $result['attachment_id'],
                'remote_url' => $url, 'message' => ! empty($result['filename_unchanged'])
                    ? 'SEO bijgewerkt. De bestaande WordPress-bestandsnaam en URL zijn behouden.' : 'Foto en SEO staan in de mediatheek. Niet aan een product gekoppeld.',
                'claim' => null,
            ]);
        } catch (\Throwable $e) {
            WordPressMediaTransfer::whereKey($transfer->id)->where('claim', $claim)->update([
                'status' => 'unconfirmed', 'message' => $this->safeError($e), 'claim' => null,
            ]);

            return response()->json(['error' => $this->safeError($e)], 422);
        }

        return response()->json($this->status($request, $asset->fresh()));
    }

    private function safeError(\Throwable $e): string
    {
        // Only our own sanitized messages may reach the UI. HTTP exceptions can contain secrets.
        return get_class($e) === \RuntimeException::class ? $e->getMessage()
            : 'Geen bevestiging ontvangen. Controleer verbinding en certificaat; opnieuw proberen controleert eerst op een bestaande upload.';
    }
}
