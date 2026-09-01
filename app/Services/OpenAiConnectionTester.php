<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OpenAiConnectionTester
{
    public function test(string $apiKey): array
    {
        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(15)
                ->get('https://api.openai.com/v1/models/'.config('services.product_images.openai.model', 'gpt-image-2'));
        } catch (ConnectionException) {
            return [
                'opslaan' => false,
                'verbonden' => false,
                'bericht' => 'OpenAI is nu niet bereikbaar. Probeer het over een paar minuten opnieuw.',
            ];
        }

        if ($response->successful()) {
            return [
                'opslaan' => true,
                'verbonden' => true,
                'bericht' => 'De verbinding met OpenAI werkt.',
            ];
        }

        if ($response->status() === 429) {
            return [
                'opslaan' => true,
                'verbonden' => false,
                'bericht' => 'De sleutel is herkend, maar de API-limiet of het budget is bereikt.',
            ];
        }

        if (in_array($response->status(), [401, 403], true)) {
            return [
                'opslaan' => false,
                'verbonden' => false,
                'bericht' => 'Deze API-sleutel is ongeldig of heeft geen toegang tot GPT Image 2.',
            ];
        }

        return [
            'opslaan' => false,
            'verbonden' => false,
            'bericht' => 'OpenAI kon de sleutel niet controleren. Probeer het later opnieuw.',
        ];
    }
}
