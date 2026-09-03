<?php

namespace Tests\Unit;

use App\Models\ProductImageStyleReference;
use App\Services\OpenAiProductImageGenerator;
use App\Services\ProductImageGenerationException;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiProductImageGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_workflow_never_runs_more_than_two_image_requests_at_once(): void
    {
        config()->set('services.product_images.driver', 'openai');
        config()->set('services.product_images.openai', [
            'api_key' => 'test-key',
            'endpoint' => 'https://api.openai.test/v1/images/edits',
            'model' => 'gpt-image-2',
            'size' => '1024x1024',
            'quality' => 'high',
            'timeout' => 30,
        ]);
        $encoded = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAFAgI/69VZ5QAAAABJRU5ErkJggg==';
        $responses = [];
        foreach (range(0, 3) as $index) {
            $responses[(string) $index] = new ClientResponse(new PsrResponse(
                200,
                ['Content-Type' => 'application/json'],
                json_encode(['data' => [['b64_json' => $encoded]]], JSON_THROW_ON_ERROR),
            ));
        }
        Http::shouldReceive('pool')
            ->once()
            ->withArgs(fn ($callback, $concurrency) => is_callable($callback) && $concurrency === 2)
            ->andReturn($responses);

        $results = app(OpenAiProductImageGenerator::class)->generateForProduct([
            UploadedFile::fake()->image('brisket.jpg', 100, 100),
        ], 'Maak een betrouwbare productfoto.', [
            'product_type' => 'meat',
            'product_name' => 'Black Angus brisket',
            'quantity' => 1,
        ]);

        $this->assertCount(4, $results);
    }

    public function test_it_requests_two_prepared_and_two_raw_variants(): void
    {
        config()->set('services.product_images.driver', 'openai');
        config()->set('services.product_images.openai', [
            'api_key' => 'test-key',
            'endpoint' => 'https://api.openai.test/v1/images/edits',
            'model' => 'gpt-image-2',
            'size' => '1024x1024',
            'output_format' => 'webp',
            'timeout' => 30,
        ]);

        $approvedPhoto = UploadedFile::fake()->image('approved.png', 100, 100);
        $encoded = base64_encode((string) file_get_contents($approvedPhoto->getRealPath()));
        Http::fakeSequence()
            ->push(['data' => [['b64_json' => $encoded], ['b64_json' => $encoded]]])
            ->push(['data' => [['b64_json' => $encoded], ['b64_json' => $encoded]]]);

        $results = app(OpenAiProductImageGenerator::class)->generate(
            UploadedFile::fake()->image('reference.jpg', 100, 100),
            'Create a premium product photo from this reference.',
        );

        $this->assertCount(4, $results);
        $this->assertSame(['bereid', 'bereid', 'rauw', 'rauw'], array_column($results, 'status'));
        $this->assertSame(['png', 'png', 'png', 'png'], array_column($results, 'extension'));

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) {
            $fields = collect($request->data())->keyBy('name');
            $prompt = (string) $fields->get('prompt')['contents'];
            $input = $fields->get('image')['contents'];
            $inputMetadata = getimagesizefromstring($input);
            $inputImage = imagecreatefromstring($input);
            $cornerAlpha = (imagecolorat($inputImage, 0, 0) >> 24) & 0x7F;
            imagedestroy($inputImage);

            return $request->url() === 'https://api.openai.test/v1/images/edits'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $request->hasFile('image', filename: 'reference.png')
                && ! $request->hasFile('image[]')
                && strlen($input) <= 4 * 1024 * 1024
                && $inputMetadata[0] === 1024
                && $inputMetadata[1] === 1024
                && $inputMetadata['mime'] === 'image/png'
                && $cornerAlpha === 0
                && $fields->get('model')['contents'] === 'gpt-image-2'
                && $fields->get('n')['contents'] === 2
                && $fields->get('size')['contents'] === '1024x1024'
                && $fields->get('output_format')['contents'] === 'png'
                && ! $fields->has('response_format')
                && $fields->get('quality')['contents'] === 'high'
                && ! $fields->has('input_fidelity')
                && $fields->get('background')['contents'] === 'opaque'
                && str_contains($prompt, 'Create a premium product photo from this reference.')
                && (str_contains($prompt, 'MANDATORY VARIANT: Show the meat fully prepared')
                    || str_contains($prompt, 'MANDATORY VARIANT: Show the meat completely raw'));
        });
    }

    public function test_concurrent_product_workflow_keeps_high_quality_for_every_variant(): void
    {
        config()->set('services.product_images.driver', 'openai');
        config()->set('services.product_images.openai', [
            'api_key' => 'test-key',
            'endpoint' => 'https://api.openai.test/v1/images/edits',
            'model' => 'gpt-image-2',
            'size' => '1024x1024',
            'quality' => 'high',
            'timeout' => 30,
        ]);
        $encoded = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAFAgI/69VZ5QAAAABJRU5ErkJggg==';
        Http::fake(['*' => Http::response(['data' => [['b64_json' => $encoded]]])]);

        $results = app(OpenAiProductImageGenerator::class)->generateForProduct([
            UploadedFile::fake()->image('brisket.jpg', 100, 100),
        ], 'Maak een betrouwbare productfoto.', [
            'product_type' => 'meat',
            'product_name' => 'Black Angus brisket',
            'quantity' => 1,
        ]);

        $this->assertCount(4, $results);
        Http::assertSentCount(4);
        Http::assertSent(function (Request $request) {
            $fields = collect($request->data())->keyBy('name');

            return ($fields->get('quality')['contents'] ?? null) === 'high'
                && ($fields->get('size')['contents'] ?? null) === '1024x1024'
                && ($fields->get('output_format')['contents'] ?? null) === 'png'
                && ($fields->get('n')['contents'] ?? null) === 1;
        });
    }

    public function test_it_rejects_incomplete_api_results(): void
    {
        config()->set('services.product_images.driver', 'openai');
        config()->set('services.product_images.openai', [
            'api_key' => 'test-key',
            'endpoint' => 'https://api.openai.test/v1/images/edits',
            'model' => 'gpt-image-2',
            'size' => '1024x1024',
            'timeout' => 30,
        ]);

        Http::fake(['*' => Http::response(['data' => [['b64_json' => base64_encode('one-image')]]])]);

        $this->expectException(ProductImageGenerationException::class);
        $this->expectExceptionMessage('onvolledig resultaat');

        app(OpenAiProductImageGenerator::class)->generate(
            UploadedFile::fake()->image('reference.jpg'),
            'Create a product photo from this reference.',
        );
    }

    public function test_new_workflow_uses_multiple_references_and_one_request_per_distinct_style(): void
    {
        config()->set('services.product_images.driver', 'openai');
        config()->set('services.product_images.openai', [
            'api_key' => 'test-key',
            'endpoint' => 'https://api.openai.test/v1/images/edits',
            'model' => 'gpt-image-2',
            'size' => '1024x1024',
            'timeout' => 30,
        ]);
        $encoded = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAFAgI/69VZ5QAAAABJRU5ErkJggg==';
        Http::fake(['*' => Http::response(['data' => [['b64_json' => $encoded]]])]);

        $results = app(OpenAiProductImageGenerator::class)->generateForProduct([
            UploadedFile::fake()->image('voor.jpg', 100, 100),
            UploadedFile::fake()->image('zij.jpg', 100, 100),
        ], 'Maak een betrouwbare productfoto.', [
            'product_type' => 'sauce',
            'product_name' => 'BBQuality The Original',
            'quantity' => 1,
        ]);

        $this->assertCount(2, $results);
        $this->assertSame(['bbquality_buiten', 'bbquality_donker'], array_column($results, 'style_id'));
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) {
            $data = collect($request->data());
            $names = $data->pluck('name');
            $prompt = (string) ($data->firstWhere('name', 'prompt')['contents'] ?? '');

            return $names->filter(fn ($name) => $name === 'image[]')->count() === 2
                && str_contains($prompt, 'HARD AANTALVEREISTE')
                && str_contains($prompt, 'Verander of verzin geen enkel woord')
                && ($data->firstWhere('name', 'n')['contents'] ?? null) === 1;
        });
    }

    public function test_styled_variants_append_one_reference_but_the_free_raw_variant_does_not(): void
    {
        config()->set('services.product_images.driver', 'openai');
        config()->set('services.product_images.openai', [
            'api_key' => 'test-key',
            'endpoint' => 'https://api.openai.test/v1/images/edits',
            'model' => 'gpt-image-2',
            'size' => '1024x1024',
            'timeout' => 30,
        ]);
        $encoded = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAFAgI/69VZ5QAAAABJRU5ErkJggg==';
        Http::fake(['*' => Http::response(['data' => [['b64_json' => $encoded]]])]);

        $results = app(OpenAiProductImageGenerator::class)->generateForProduct([
            UploadedFile::fake()->image('verpakking-voor.jpg', 100, 100),
            UploadedFile::fake()->image('verpakking-achter.jpg', 100, 100),
        ], 'Maak een betrouwbare productfoto.', [
            'product_type' => 'meat',
            'product_name' => 'Black Angus brisket',
            'quantity' => 1,
        ]);

        $this->assertSame(
            ['bbq_buiten_brisket', 'serveerbeeld_brisket', 'rauw_studio', 'rauw_licht'],
            array_column($results, 'style_id'),
        );

        $requests = Http::recorded()->map(fn (array $record) => $record[0]);
        $this->assertCount(4, $requests);

        foreach ($requests as $index => $request) {
            $files = collect($request->data())
                ->filter(fn (array $field) => $field['name'] === 'image[]');
            $filenames = $files->pluck('filename')->filter()->values();
            $prompt = (string) (collect($request->data())->firstWhere('name', 'prompt')['contents'] ?? '');

            if ($index < 3) {
                $this->assertCount(3, $files);
                $this->assertCount(1, $filenames->filter(fn (string $filename) => str_starts_with($filename, 'style-')));
                $this->assertStringContainsString('De allerlaatste afbeelding', $prompt);
                if ($index === 2) {
                    $this->assertTrue($filenames->contains('style-rauw-bbquality-vast.png'));
                    $this->assertStringContainsString('LEGE VASTE BBQUALITY-ACHTERGRONDREFERENTIE', $prompt);
                    $this->assertStringContainsString('de enige bron voor de vorm', $prompt);
                }
            } else {
                $this->assertCount(2, $files);
                $this->assertCount(0, $filenames->filter(fn (string $filename) => str_starts_with($filename, 'style-')));
                $this->assertStringNotContainsString('De allerlaatste afbeelding', $prompt);
            }
        }
    }

    public function test_an_approved_matching_photo_replaces_the_bundled_cooked_style_reference(): void
    {
        config()->set('services.product_images.driver', 'openai');
        config()->set('services.product_images.openai', [
            'api_key' => 'test-key',
            'endpoint' => 'https://api.openai.test/v1/images/edits',
            'model' => 'gpt-image-2',
            'size' => '1024x1024',
            'timeout' => 30,
        ]);
        $approvedPhoto = UploadedFile::fake()->image('approved.png', 100, 100);
        $encoded = base64_encode((string) file_get_contents($approvedPhoto->getRealPath()));
        ProductImageStyleReference::create([
            'product_name' => 'Black Angus brisket',
            'product_key' => 'black-angus-brisket',
            'product_type' => 'meat',
            'status' => 'bereid',
            'style_id' => 'bbq_buiten_brisket',
            'source_version' => 1,
            'mime_type' => 'image/png',
            'contents_base64' => $encoded,
        ]);
        Http::fake(['*' => Http::response(['data' => [['b64_json' => $encoded]]])]);

        app(OpenAiProductImageGenerator::class)->generateForProduct([
            UploadedFile::fake()->image('brisket.jpg', 100, 100),
        ], 'Maak een betrouwbare productfoto.', [
            'product_type' => 'meat',
            'product_name' => 'Black Angus brisket',
            'quantity' => 1,
        ]);

        $firstRequest = Http::recorded()->first()[0];
        $data = collect($firstRequest->data());
        $filenames = $data->where('name', 'image[]')->pluck('filename')->filter();
        $prompt = (string) ($data->firstWhere('name', 'prompt')['contents'] ?? '');

        $this->assertCount(2, $filenames);
        $this->assertCount(1, $filenames->filter(fn (string $filename) => str_starts_with($filename, 'approved-')));
        $this->assertFalse($filenames->contains('style-bbq-outdoor-kamado.png'));
        $this->assertStringContainsString('door BBQuality goedgekeurde eerdere foto', $prompt);
        $this->assertStringContainsString('actuele echte productreferenties', $prompt);
    }

    public function test_it_never_sends_a_request_without_an_api_key(): void
    {
        config()->set('services.product_images.driver', 'openai');
        config()->set('services.product_images.openai.api_key', null);
        Http::fake();

        $this->expectException(ProductImageGenerationException::class);
        $this->expectExceptionMessage('API-sleutel is niet ingesteld');

        try {
            app(OpenAiProductImageGenerator::class)->generate(
                UploadedFile::fake()->image('reference.jpg'),
                'Create a product photo from this reference.',
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_it_reports_when_api_credit_is_exhausted(): void
    {
        config()->set('services.product_images.driver', 'openai');
        config()->set('services.product_images.openai.api_key', 'test-key');
        Http::fake(['*' => Http::response([
            'error' => [
                'code' => 'insufficient_quota',
                'type' => 'insufficient_quota',
                'message' => 'You exceeded your current quota.',
            ],
        ], 429)]);

        $this->expectException(ProductImageGenerationException::class);
        $this->expectExceptionMessage('API-tegoed');

        app(OpenAiProductImageGenerator::class)->generate(
            UploadedFile::fake()->image('reference.jpg'),
            'Create a product photo from this reference.',
        );
    }

    public function test_it_reports_when_organization_verification_is_required(): void
    {
        config()->set('services.product_images.driver', 'openai');
        config()->set('services.product_images.openai.api_key', 'test-key');
        Http::fake(['*' => Http::response([
            'error' => [
                'code' => 'organization_verification_required',
                'type' => 'invalid_request_error',
                'message' => 'Your organization must be verified.',
            ],
        ], 403)]);

        $this->expectException(ProductImageGenerationException::class);
        $this->expectExceptionMessage('organisatie moet eerst worden geverifieerd');

        app(OpenAiProductImageGenerator::class)->generate(
            UploadedFile::fake()->image('reference.jpg'),
            'Create a product photo from this reference.',
        );
    }
}
