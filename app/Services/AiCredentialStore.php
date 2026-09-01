<?php

namespace App\Services;

use App\Models\AiProviderSetting;
use Illuminate\Support\Facades\Schema;

class AiCredentialStore
{
    public const OPENAI = 'openai';

    public function openAiApiKey(): ?string
    {
        $storedKey = $this->storedOpenAiApiKey();
        if ($storedKey !== null) {
            return $storedKey;
        }

        if (config('services.product_images.driver') !== 'openai') {
            return null;
        }

        $environmentKey = trim((string) config('services.product_images.openai.api_key'));

        return $environmentKey !== '' ? $environmentKey : null;
    }

    public function openAiIsActive(): bool
    {
        return $this->openAiApiKey() !== null;
    }

    public function status(): array
    {
        $storedKey = $this->storedOpenAiApiKey();
        $environmentKey = trim((string) config('services.product_images.openai.api_key'));
        $environmentActive = config('services.product_images.driver') === 'openai' && $environmentKey !== '';
        $activeKey = $storedKey ?? ($environmentActive ? $environmentKey : null);

        return [
            'ingesteld' => $activeKey !== null,
            'actief' => $activeKey !== null,
            'weergave' => $activeKey !== null ? $this->mask($activeKey) : null,
            'bron' => $storedKey !== null ? 'app' : ($environmentActive ? 'server' : null),
            'model' => (string) config('services.product_images.openai.model', 'gpt-image-2'),
        ];
    }

    public function storeOpenAiApiKey(string $apiKey, string $userId): void
    {
        AiProviderSetting::query()->updateOrCreate(
            ['provider' => self::OPENAI],
            ['api_key' => trim($apiKey), 'updated_by' => $userId],
        );
    }

    public function deleteStoredOpenAiApiKey(): void
    {
        AiProviderSetting::query()->where('provider', self::OPENAI)->delete();
    }

    private function storedOpenAiApiKey(): ?string
    {
        if (! Schema::hasTable('ai_provider_settings')) {
            return null;
        }

        $key = AiProviderSetting::query()
            ->where('provider', self::OPENAI)
            ->value('api_key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    private function mask(string $apiKey): string
    {
        return '••••••••'.substr($apiKey, -4);
    }
}
