<?php

namespace Tests\Unit;

use App\Models\ImagePrompt;
use App\Models\ProductImageStyleReference;
use App\Services\OpenAiProductImageGenerator;
use App\Services\ProductImageFormat;
use App\Services\ProductImageGenerationException;
use App\Services\ProductImageModelCatalog;
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

    public function test_generation_and_refinement_use_the_pinned_model_and_keep_high_quality(): void
    {
        config(['services.product_images.driver' => 'openai', 'services.product_images.openai.api_key' => 'test-key']);
        app(ProductImageModelCatalog::class)->saveDefault('gpt-image-2');
        $photo = UploadedFile::fake()->image('test-reference.png', 40, 40);
        $encoded = base64_encode(ProductImageFormat::placeholder());
        Http::fake(['*' => Http::response(['data' => [['b64_json' => $encoded]]])]);
        $generator = app(OpenAiProductImageGenerator::class);
        $context = ['product_type' => 'meat', 'product_name' => 'Test ribeye', 'quantity' => 1, 'image_model' => 'gpt-image-2.5-sunburst'];
        $this->assertCount(5, $generator->generateForProduct([$photo], ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT, $context));
        $cookedRequests = 0;
        foreach (Http::recorded() as [$request]) {
            $fields = collect($request->data())->keyBy('name');
            $prompt = $fields['prompt']['contents'];
            if (str_contains($prompt, 'BRONBEHOUD BIJ BEREIDING')) {
                $cookedRequests++;
                $this->assertStringContainsString('FOTOGRAFIE BEREID VLEES: zacht diffuus zijlicht', $prompt);
                if (! str_contains($prompt, 'lichte moderne woonkeuken')) {
                    $this->assertStringContainsString('geen referentie voor korst, vleesvezels', $prompt);
                }
                $this->assertStringContainsString('niet magerder of vetter op basis van algemene aannames over de diersoort', $prompt);
                $this->assertStringContainsString('Een vetnaad mag dus glanzen zonder dat de hele korst een olieachtige glans krijgt', $prompt);
                $this->assertStringNotContainsString('LEGE VASTE BBQUALITY', $prompt);
            } else {
                $this->assertStringStartsWith(ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT, $prompt);
                $this->assertStringNotContainsString('FOTOGRAFIE BEREID VLEES', $prompt);
            }
        }
        $this->assertSame(3, $cookedRequests);
        $generator->refine($photo, 'Behoud het vlees en pas het licht aan.', $context);
        Http::assertSentCount(6);
        foreach (Http::recorded() as [$request]) {
            $fields = collect($request->data())->keyBy('name');
            $this->assertSame('gpt-image-2.5-sunburst', $fields['model']['contents']);
            $this->assertSame('high', $fields['quality']['contents']);
            $this->assertSame('1536x1152', $fields['size']['contents']);
            $this->assertStringContainsString(ProductImageFormat::INSTRUCTION, $fields['prompt']['contents']);
            $this->assertSame('png', $fields['output_format']['contents']);
            $this->assertFalse($fields->has('input_fidelity'));
        }
    }

    public function test_unavailable_model_fails_without_sending_an_alternative_request(): void
    {
        config(['services.product_images.driver' => 'openai', 'services.product_images.openai.api_key' => 'test-key']);
        Http::fake(['*' => Http::response(['error' => ['code' => 'model_not_found']], 404)]);
        $this->expectException(ProductImageGenerationException::class);
        $this->expectExceptionMessage('niet automatisch gewisseld');
        try {
            app(OpenAiProductImageGenerator::class)->refine(UploadedFile::fake()->image('test.png'), 'Pas licht aan.', ['image_model' => 'gpt-image-2.5-flare']);
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_square_provider_output_is_not_silently_cropped_or_retried(): void
    {
        config(['services.product_images.driver' => 'openai', 'services.product_images.openai.api_key' => 'test-key']);
        $square = UploadedFile::fake()->image('square.png', 64, 64);
        Http::fake(['*' => Http::response(['data' => [['b64_json' => base64_encode(file_get_contents($square->getRealPath()))]]])]);
        $this->expectException(ProductImageGenerationException::class);
        $this->expectExceptionMessage('1536 × 1152');
        try {
            app(OpenAiProductImageGenerator::class)->refine($square, 'Pas licht aan.', ['image_model' => 'gpt-image-2.5-flare']);
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_sucade_requests_stew_scenes_and_only_reuses_approved_stew_references(): void
    {
        config(['services.product_images.driver' => 'openai', 'services.product_images.openai.api_key' => 'test-key']);
        $source = UploadedFile::fake()->image('sucade.png', 40, 40);
        $encoded = base64_encode(ProductImageFormat::placeholder());
        $approvedId = null;
        foreach (['bbq_buiten_algemeen', 'serveerbeeld_algemeen', 'bbq_buiten_stoof'] as $styleId) {
            $reference = ProductImageStyleReference::create([
                'product_name' => 'Kalfssucade',
                'product_key' => 'kalfssucade',
                'product_type' => 'meat',
                'status' => 'bereid',
                'style_id' => $styleId,
                'source_version' => 1,
                'mime_type' => 'image/png',
                'contents_base64' => $encoded,
            ]);
            if ($styleId === 'bbq_buiten_stoof') {
                $approvedId = $reference->id;
            }
        }
        Http::fake(['*' => Http::response(['data' => [['b64_json' => $encoded]]])]);
        $results = app(OpenAiProductImageGenerator::class)->generateForProduct([$source], ImagePrompt::DEFAULT_PRODUCT_PHOTO_PROMPT, [
            'product_type' => 'meat', 'product_name' => 'Kalfssucade', 'quantity' => 1, 'image_model' => 'gpt-image-2.5-sunburst',
        ]);

        $this->assertSame(['bbq_buiten_stoof', 'serveerbeeld_stoof', 'rauw_studio', 'rauw_licht', 'keuken_licht_stoof'], array_column($results, 'style_id'));
        Http::assertSentCount(5);
        foreach (Http::recorded() as $index => [$request]) {
            $data = collect($request->data());
            $fields = $data->keyBy('name');
            $filenames = $data->whereIn('name', ['image', 'image[]'])->pluck('filename')->values()->all();
            $prompt = $fields['prompt']['contents'];
            $this->assertSame('gpt-image-2.5-sunburst', $fields['model']['contents']);
            $this->assertSame('high', $fields['quality']['contents']);
            $this->assertSame('1536x1152', $fields['size']['contents']);
            $this->assertSame('png', $fields['output_format']['contents']);

            if ($index < 2 || $index === 4) {
                $this->assertStringContainsString('GEEN SNIJPLAKKEN', $prompt);
                $this->assertStringContainsString('BRONBEHOUD BIJ STOVEN', $prompt);
                $this->assertStringNotContainsString('zichtbare plankrand', $prompt);
                $this->assertStringNotContainsString('LEGE VASTE BBQUALITY', $prompt);
                // No generic roast, brisket or kamado image is attached to a stew scene.
                $this->assertSame($index === 0
                    ? ['product-reference-1.png', 'approved-'.$approvedId.'.png']
                    : ['product-reference-1.png'], $filenames);
                if ($index === 0) {
                    $this->assertStringContainsString('Afbeelding 2 is een door BBQuality goedgekeurd stoofvoorbeeld', $prompt);
                } else {
                    $this->assertStringNotContainsString('De allerlaatste afbeelding', $prompt);
                }
            } else {
                $this->assertStringNotContainsString('BRONBEHOUD BIJ STOVEN', $prompt);
                $this->assertSame($index === 2
                    ? ['product-reference-1.png', 'style-rauw-bbquality-vast.png']
                    : ['product-reference-1.png'], $filenames);
            }
        }
        $this->assertSame(3, ProductImageStyleReference::count());
    }

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
        $encoded = base64_encode(ProductImageFormat::placeholder());
        $responses = [];
        foreach (range(0, 4) as $index) {
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

        $this->assertCount(5, $results);
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
        $encoded = base64_encode(ProductImageFormat::placeholder());
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
                && $fields->get('size')['contents'] === '1536x1152'
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
        $encoded = base64_encode(ProductImageFormat::placeholder());
        Http::fake(['*' => Http::response(['data' => [['b64_json' => $encoded]]])]);

        $results = app(OpenAiProductImageGenerator::class)->generateForProduct([
            UploadedFile::fake()->image('brisket.jpg', 100, 100),
        ], 'Maak een betrouwbare productfoto.', [
            'product_type' => 'meat',
            'product_name' => 'Black Angus brisket',
            'quantity' => 1,
        ]);

        $this->assertCount(5, $results);
        Http::assertSentCount(5);
        Http::assertSent(function (Request $request) {
            $fields = collect($request->data())->keyBy('name');

            return ($fields->get('quality')['contents'] ?? null) === 'high'
                && ($fields->get('size')['contents'] ?? null) === '1536x1152'
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
        $encoded = base64_encode(ProductImageFormat::placeholder());
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
        $encoded = base64_encode(ProductImageFormat::placeholder());
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
            ['bbq_buiten_brisket', 'serveerbeeld_brisket', 'rauw_studio', 'rauw_licht', 'keuken_licht_brisket'],
            array_column($results, 'style_id'),
        );

        $requests = Http::recorded()->map(fn (array $record) => $record[0]);
        $this->assertCount(5, $requests);

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
        $encoded = base64_encode(ProductImageFormat::placeholder());
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
