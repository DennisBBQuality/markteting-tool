<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiCredentialStore;
use App\Services\OpenAiConnectionTester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiSettingController extends Controller
{
    public function show(AiCredentialStore $credentials): JsonResponse
    {
        return response()->json($credentials->status());
    }

    public function update(
        Request $request,
        AiCredentialStore $credentials,
        OpenAiConnectionTester $tester,
    ): JsonResponse {
        $validated = $request->validate([
            'api_key' => ['required', 'string', 'min:20', 'max:512', 'regex:/^sk-[A-Za-z0-9_-]+$/'],
        ], [
            'api_key.regex' => 'Dit lijkt niet op een geldige OpenAI API-sleutel.',
        ]);

        $result = $tester->test($validated['api_key']);
        if (! $result['opslaan']) {
            return response()->json(['error' => $result['bericht']], 422);
        }

        $credentials->storeOpenAiApiKey(
            $validated['api_key'],
            (string) $request->attributes->get('authenticatedUser')->id,
        );

        return response()->json([
            ...$credentials->status(),
            'verbonden' => $result['verbonden'],
            'bericht' => $result['bericht'],
        ]);
    }

    public function test(AiCredentialStore $credentials, OpenAiConnectionTester $tester): JsonResponse
    {
        $apiKey = $credentials->openAiApiKey();
        if ($apiKey === null) {
            return response()->json(['error' => 'Er is nog geen OpenAI API-sleutel ingesteld.'], 422);
        }

        return response()->json($tester->test($apiKey));
    }

    public function destroy(AiCredentialStore $credentials): JsonResponse
    {
        $credentials->deleteStoredOpenAiApiKey();

        return response()->json([
            ...$credentials->status(),
            'bericht' => $credentials->openAiIsActive()
                ? 'De sleutel uit de app is verwijderd. De serverinstelling blijft actief.'
                : 'De OpenAI API-sleutel is verwijderd. De voorbeeldmodus is weer actief.',
        ]);
    }
}
