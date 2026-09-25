<?php

namespace Tests\Feature;

use App\Jobs\GenerateProductPage;
use App\Models\AiProviderSetting;
use App\Models\ProductDossier;
use App\Models\User;
use App\Services\ProductDossierAiService;
use App\Services\ProductDossierMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductDossierTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'sk-project_test_12345678901234567890';

    public function test_product_dossier_endpoints_require_authentication(): void
    {
        $user = User::factory()->create();
        $dossier = ProductDossier::create([
            'user_id' => $user->id,
            'status' => 'concept',
        ]);

        $this->getJson('/api/product-dossiers')->assertUnauthorized();
        $this->getJson('/api/product-dossiers/ai-status')->assertUnauthorized();
        $this->postJson('/api/product-dossiers', [])->assertUnauthorized();
        $this->getJson('/api/product-dossiers/'.$dossier->id)->assertUnauthorized();
    }

    public function test_ai_status_is_safe_and_reports_the_shared_connection(): void
    {
        $user = $this->actingAsUser();

        $this->getJson('/api/product-dossiers/ai-status')
            ->assertOk()
            ->assertExactJson([
                'active' => false,
                'model' => 'gpt-5.6-sol',
            ]);

        AiProviderSetting::create([
            'provider' => 'openai',
            'api_key' => self::API_KEY,
            'updated_by' => $user->id,
        ]);

        $response = $this->getJson('/api/product-dossiers/ai-status')
            ->assertOk()
            ->assertJsonPath('active', true);

        $this->assertStringNotContainsString(self::API_KEY, $response->getContent());
    }

    public function test_product_name_is_required_but_other_fields_remain_optional(): void
    {
        $user = $this->actingAsUser();

        $this->postJson('/api/product-dossiers', [])->assertJsonValidationErrors('product_name');

        $response = $this->postJson('/api/product-dossiers', ['product_name' => 'Nieuw testproduct']);

        $response
            ->assertCreated()
            ->assertJsonPath('status', 'concept')
            ->assertJsonPath('product_name', 'Nieuw testproduct')
            ->assertJsonPath('product_type', null)
            ->assertJsonPath('wordpress_status', 'niet_gekoppeld');

        $this->assertDatabaseHas('product_dossiers', [
            'id' => $response->json('id'),
            'user_id' => $user->id,
            'product_name' => 'Nieuw testproduct',
        ]);
    }

    public function test_configurable_product_choices_are_seeded_and_can_be_managed(): void
    {
        $this->actingAsUser(['rol' => 'admin']);

        $this->getJson('/api/product-dossier-options')
            ->assertOk()
            ->assertJsonFragment(['label' => 'Brisket'])
            ->assertJsonFragment(['label' => 'El Rancho'])
            ->assertJsonFragment(['label' => 'Rund']);

        $created = $this->postJson('/api/product-dossier-options', [
            'type' => 'selection',
            'label' => 'Nieuwe selectie',
        ])->assertCreated();

        $this->assertDatabaseHas('product_dossier_options', ['type' => 'selection', 'label' => 'Nieuwe selectie']);
        $this->deleteJson('/api/product-dossier-options/'.$created->json('id'))->assertOk();
        $this->assertDatabaseMissing('product_dossier_options', ['label' => 'Nieuwe selectie']);
    }

    public function test_users_only_see_and_open_their_own_dossiers(): void
    {
        $owner = $this->actingAsUser();
        $own = ProductDossier::create(['user_id' => $owner->id, 'product_name' => 'Eigen product']);
        $otherUser = User::factory()->create();
        $other = ProductDossier::create(['user_id' => $otherUser->id, 'product_name' => 'Ander product']);

        $this->getJson('/api/product-dossiers')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $own->id);

        $this->getJson('/api/product-dossiers/'.$other->id)->assertNotFound();
        $this->putJson('/api/product-dossiers/'.$other->id, ['product_name' => 'Gewijzigd'])->assertNotFound();
    }

    public function test_legacy_legal_label_names_are_removed_from_the_editor_data(): void
    {
        $user = $this->actingAsUser();
        $dossier = ProductDossier::create([
            'user_id' => $user->id,
            'product_name' => 'Black Angus brisket',
            'data' => [
                'facts' => ['origin' => 'Uruguay', 'legal_name' => 'CHILLED BONELESS BEEF'],
                'nutrition' => [
                    'basis' => 'Per 100 g, rauw gekoeld rundvlees zonder been',
                    'source_status' => 'ai_schatting',
                ],
            ],
            'label_analysis' => ['wettelijke_naam' => 'Gekoeld rundvlees zonder been'],
        ]);

        $response = $this->getJson('/api/product-dossiers/'.$dossier->id)->assertOk();
        $payload = $response->json();

        $this->assertArrayNotHasKey('legal_name', $payload['data']['facts']);
        $this->assertNull($payload['label_analysis']);
        $this->assertSame('Per 100 g, rauw product', $payload['data']['nutrition']['basis']);

        $this->putJson('/api/product-dossiers/'.$dossier->id, [
            'product_name' => $dossier->product_name,
            'data' => $payload['data'],
        ])->assertOk();

        $saved = ProductDossier::findOrFail($dossier->id)->data;
        $this->assertArrayNotHasKey('legal_name', $saved['facts']);
        $this->assertSame('Per 100 g, rauw product', $saved['nutrition']['basis']);
    }

    public function test_optional_label_photos_are_stored_privately_and_can_be_analyzed(): void
    {
        Storage::fake('local');
        $user = $this->actingAsUser();
        AiProviderSetting::create([
            'provider' => 'openai',
            'api_key' => self::API_KEY,
            'updated_by' => $user->id,
        ]);
        $dossier = ProductDossier::create([
            'user_id' => $user->id,
            'status' => 'concept',
            'data' => ['source_notes' => 'Leverancier noemt dit een saus van 250 ml.'],
        ]);

        $upload = $this->post('/api/product-dossiers/'.$dossier->id.'/labels', [
            'labels' => [UploadedFile::fake()->image('etiket-voorzijde.jpg', 900, 1200)],
        ], ['Accept' => 'application/json']);

        $upload
            ->assertOk()
            ->assertJsonPath('analysis_status', 'klaar_voor_analyse')
            ->assertJsonPath('label_images.0.original_name', 'etiket-voorzijde.jpg');

        $stored = ProductDossier::findOrFail($dossier->id)->label_images[0];
        $this->assertDatabaseHas('product_dossier_assets', ['id' => $stored['asset_id'], 'product_dossier_id' => $dossier->id]);
        $this->assertNotNull(ProductDossierMedia::contents($dossier->id, $stored));

        $this->post('/api/product-dossiers/'.$dossier->id.'/labels', [
            'labels' => [UploadedFile::fake()->image('etiket-achterzijde.jpg', 900, 1200)],
            'append' => true,
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonCount(2, 'label_images')
            ->assertJsonPath('label_images.0.original_name', 'etiket-voorzijde.jpg')
            ->assertJsonPath('label_images.1.original_name', 'etiket-achterzijde.jpg');

        $analysis = [
            'productnaam' => 'BBQuality The Test',
            'merk' => 'BBQuality',
            'producttype' => 'saus',
            'inhoud' => '250 ml',
            'herkomst' => null,
            'producent' => null,
            'bewaaradvies' => 'Na openen gekoeld bewaren.',
            'bereidingsadvies' => null,
            'ingrediënten' => 'Tomaat, water, suiker.',
            'ingrediënten_bron' => 'etiket',
            'allergenen' => 'Geen declarabele allergenen verwacht.',
            'allergenen_bron' => 'ai_schatting',
            'voedingswaarden' => array_fill_keys([
                'basis', 'energie_kj', 'energie_kcal', 'vetten', 'verzadigde_vetten',
                'koolhydraten', 'suikers', 'eiwitten', 'zout',
            ], null),
            'claims' => [],
            'waarschuwingen' => [],
        ];
        Http::fake([
            'api.openai.com/v1/responses' => Http::response(['output_text' => json_encode($analysis, JSON_UNESCAPED_UNICODE)]),
        ]);

        $this->postJson('/api/product-dossiers/'.$dossier->id.'/analyze')
            ->assertOk()
            ->assertJsonPath('analysis_status', 'geanalyseerd')
            ->assertJsonPath('label_analysis.productnaam', 'BBQuality The Test')
            ->assertJsonPath('label_analysis.ingrediënten', 'Tomaat, water, suiker.')
            ->assertJsonPath('data.ingredients', 'Tomaat, water, suiker.')
            ->assertJsonPath('data.ingredients_source_status', 'etiket')
            ->assertJsonPath('data.allergens', 'Geen declarabele allergenen verwacht.')
            ->assertJsonPath('data.allergens_source_status', 'ai_schatting');

        Http::assertSent(function (Request $request): bool {
            $content = $request->data()['input'][0]['content'] ?? [];

            return $request->url() === 'https://api.openai.com/v1/responses'
                && collect($content)->where('type', 'input_image')->count() === 2
                && collect($content)->where('type', 'input_image')->every(
                    fn (array $part) => str_starts_with((string) ($part['image_url'] ?? ''), 'data:image/jpeg;base64,')
                );
        });
    }

    public function test_ai_nutrition_is_persisted_as_an_estimate(): void
    {
        $user = $this->actingAsUser();
        AiProviderSetting::create([
            'provider' => 'openai',
            'api_key' => self::API_KEY,
            'updated_by' => $user->id,
        ]);
        $dossier = ProductDossier::create([
            'user_id' => $user->id,
            'status' => 'concept',
            'data' => ['facts' => ['product_name' => 'Rundersteak']],
        ]);
        $nutrition = [
            'basis' => 'per 100 g', 'energie_kj' => 'ca. 800 kJ', 'energie_kcal' => 'ca. 190 kcal',
            'vetten' => 'ca. 10 g', 'verzadigde_vetten' => 'ca. 4 g', 'koolhydraten' => '0 g',
            'suikers' => '0 g', 'eiwitten' => 'ca. 24 g', 'zout' => 'ca. 0,1 g',
        ];
        Http::fake([
            'api.openai.com/v1/responses' => Http::response(['output_text' => json_encode([
                'voedingswaarden' => $nutrition,
                'aannames' => ['Onbewerkte rundersteak.'],
                'waarschuwing' => 'Dit is een AI-schatting.',
            ], JSON_UNESCAPED_UNICODE)]),
        ]);

        $this->postJson('/api/product-dossiers/'.$dossier->id.'/estimate-nutrition')
            ->assertOk()
            ->assertJsonPath('estimate.voedingswaarden.eiwitten', 'ca. 24 g')
            ->assertJsonPath('dossier.data.nutrition.source_status', 'ai_schatting');

        $saved = ProductDossier::findOrFail($dossier->id);
        $this->assertSame('ai_schatting', $saved->data['nutrition']['source_status']);
        $this->assertSame('Dit is een AI-schatting.', $saved->data['nutrition']['warning']);
    }

    public function test_page_generation_requires_origin_and_persists_structured_copy(): void
    {
        $user = $this->actingAsUser();
        AiProviderSetting::create([
            'provider' => 'openai',
            'api_key' => self::API_KEY,
            'updated_by' => $user->id,
        ]);
        $dossier = ProductDossier::create([
            'user_id' => $user->id,
            'product_name' => 'Black Angus brisket',
            'product_type' => 'meat',
            'data' => ['facts' => ['category' => 'Rund', 'cut' => 'Brisket', 'selection' => 'El Rancho', 'legal_name' => 'CHILLED BONELESS BEEF']],
        ]);

        $this->postJson('/api/product-dossiers/'.$dossier->id.'/generate-page')
            ->assertUnprocessable()
            ->assertJsonPath('error', 'Vul eerst de herkomst in.');

        $dossier->update(['data' => ['facts' => ['category' => 'Rund', 'cut' => 'Brisket', 'selection' => 'El Rancho', 'origin' => 'Uruguay', 'legal_name' => 'CHILLED BONELESS BEEF']]]);
        $nutrition = array_fill_keys([
            'basis', 'energie_kj', 'energie_kcal', 'vetten', 'verzadigde_vetten',
            'koolhydraten', 'suikers', 'eiwitten', 'zout',
        ], null) + [
            'source_status' => 'etiket',
            'aannames' => ['Onbewerkt rundvlees.'],
            'waarschuwing' => 'Controleer met leveranciersinformatie.',
        ];
        $nutrition['energie_kcal'] = 'ca. 250 kcal';
        $generated = [
            'korte_introductie' => 'Black Angus brisket is een stevig stuk rundvlees uit de borst van het rund. Deze snit bevat van nature bindweefsel en heeft daarom tijd nodig om mooi zacht te worden. Tijdens een rustige bereiding verandert het bindweefsel in gelatine en blijft het vlees sappig. Dat maakt brisket een heerlijk stuk voor een lange sessie op de BBQ of smoker, met volop ruimte voor een mooie kruidige korst en een volle rundvleessmaak.',
            'secties' => collect(range(1, 4))->map(fn (int $index) => [
                'kop' => 'Sectie '.$index,
                'tekst' => 'Dit tekstblok vertelt concreet wat dit kenmerk voor de brisket betekent. Het koppelt de structuur van het vlees aan het resultaat tijdens een rustige bereiding en voegt nieuwe productinformatie toe. Zo krijgt de klant een duidelijk en smakelijk beeld zonder dezelfde uitleg uit de introductie te herhalen.',
            ])->all(),
            'faqs' => collect(range(1, 6))->map(fn (int $index) => ['vraag' => 'Vraag '.$index.'?', 'antwoord' => 'Antwoord '.$index.'.'])->all(),
            'ingrediënten' => ['waarde' => '100% rundvlees', 'source_status' => 'etiket'],
            'allergenen' => ['waarde' => null, 'source_status' => 'onbekend'],
            'voedingswaarden' => $nutrition,
            'seo' => [
                'titel' => 'Black Angus brisket kopen | BBQuality',
                'metaomschrijving' => 'Ontdek Black Angus brisket uit Uruguay bij BBQuality.',
                'slug' => 'black-angus-brisket',
                'entiteitssamenvatting' => 'Black Angus brisket is een rundvleessnit uit Uruguay.',
            ],
            'controlepunten' => ['Controleer voedingswaarden.'],
        ];
        Http::fake([
            'api.openai.com/v1/responses' => Http::response(['output_text' => json_encode($generated, JSON_UNESCAPED_UNICODE)]),
        ]);

        Queue::fake();
        $this->postJson('/api/product-dossiers/'.$dossier->id.'/generate-page')->assertAccepted();
        Queue::pushed(GenerateProductPage::class)->first()->handle(app(ProductDossierAiService::class));
        $this->getJson('/api/product-dossiers/'.$dossier->id)
            ->assertOk()
            ->assertJsonPath('status', 'controle')
            ->assertJsonCount(6, 'data.content.faqs')
            ->assertJsonPath('data.content.generation_version', 5)
            ->assertJsonPath('data.content.seo.slug', 'black-angus-brisket')
            ->assertJsonPath('data.ingredients_source_status', 'ai_schatting');

        $saved = ProductDossier::findOrFail($dossier->id);
        $this->assertCount(6, $saved->data['content']['faqs']);
        $this->assertSame('ai_schatting', $saved->data['nutrition']['source_status']);

        Http::assertSent(function (Request $request): bool {
            $input = json_encode($request->data()['input'] ?? [], JSON_UNESCAPED_UNICODE);

            return ! str_contains($input, 'CHILLED BONELESS BEEF')
                && str_contains($input, 'wettelijke benaming')
                && str_contains($input, 'productfeiten')
                && str_contains($input, 'Je krijgt zowel de flat als de point')
                && ! str_contains($input, 'MIJ-technologie');
        });
    }

    public function test_customer_copy_with_label_language_is_repaired_before_it_is_saved(): void
    {
        $user = $this->actingAsUser();
        AiProviderSetting::create([
            'provider' => 'openai',
            'api_key' => self::API_KEY,
            'updated_by' => $user->id,
        ]);
        $dossier = ProductDossier::create([
            'user_id' => $user->id,
            'product_name' => 'Black Angus brisket whole packer',
            'product_type' => 'meat',
            'data' => ['facts' => ['cut' => 'Brisket', 'origin' => 'Uruguay']],
        ]);
        $nutrition = array_fill_keys([
            'basis', 'energie_kj', 'energie_kcal', 'vetten', 'verzadigde_vetten',
            'koolhydraten', 'suikers', 'eiwitten', 'zout',
        ], null) + [
            'source_status' => 'onbekend',
            'aannames' => [],
            'waarschuwing' => null,
        ];
        $page = fn (string $intro): array => [
            'korte_introductie' => $intro,
            'secties' => collect(range(1, 3))->map(fn (int $index) => [
                'kop' => 'Productkenmerk '.$index,
                'tekst' => 'Dit tekstblok geeft duidelijke productinformatie over de brisket en legt concreet uit wat het kenmerk voor smaak en structuur betekent. Het voegt een eigen onderwerp toe, gebruikt gewone Nederlandse woorden en helpt de klant om het stuk vlees beter te begrijpen zonder de introductie te herhalen.',
            ])->all(),
            'faqs' => collect(range(1, 5))->map(fn (int $index) => [
                'vraag' => 'Wat kenmerkt dit product '.$index.'?',
                'antwoord' => 'Een duidelijk kenmerk van de brisket '.$index.'.',
            ])->all(),
            'ingrediënten' => ['waarde' => '100% rundvlees', 'source_status' => 'ai_schatting'],
            'allergenen' => ['waarde' => null, 'source_status' => 'onbekend'],
            'voedingswaarden' => $nutrition,
            'seo' => [
                'titel' => 'Black Angus brisket whole packer | BBQuality',
                'metaomschrijving' => 'Black Angus brisket whole packer uit Uruguay.',
                'slug' => 'black-angus-brisket-whole-packer',
                'entiteitssamenvatting' => 'Een complete brisket van Black Angus-rund uit Uruguay.',
            ],
            'controlepunten' => [],
        ];

        $approvedIntroduction = 'Black Angus brisket whole packer bevat de flat en de point in één stuk.';
        Http::fakeSequence()
            ->push(['output_text' => json_encode($page('Volgens het etiket geeft de herkomst dit product een duidelijke rundvleespositionering.'), JSON_UNESCAPED_UNICODE)])
            ->push(['output_text' => json_encode($page($approvedIntroduction), JSON_UNESCAPED_UNICODE)]);
        Queue::fake();
        $this->postJson('/api/product-dossiers/'.$dossier->id.'/generate-page')->assertAccepted();
        Queue::pushed(GenerateProductPage::class)->first()->handle(app(ProductDossierAiService::class));
        $this->getJson('/api/product-dossiers/'.$dossier->id)->assertOk()
            ->assertJsonPath('data.content.short_description', $approvedIntroduction);
        $this->assertCount(2, Http::recorded());
        $savedCopy = json_encode(ProductDossier::findOrFail($dossier->id)->data['content'], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('gekoeld rundvlees zonder been', $savedCopy);
        $this->assertStringNotContainsString('rundvleespositionering', $savedCopy);
        $this->assertStringNotContainsString('Volgens', $savedCopy);
    }

    public function test_browser_keeps_a_pending_label_when_uploading_fails(): void
    {
        $javascript = file_get_contents(public_path('js/product-dossiers.js'));

        $this->assertIsString($javascript);
        $this->assertStringContainsString('if (!uploaded)', $javascript);
        $this->assertStringContainsString('pendingLabels[index]', $javascript);
        $this->assertStringContainsString("formData.append('append', index === 0 ? '0' : '1')", $javascript);
        $this->assertStringContainsString('productDossierState.currentId = saved.id', $javascript);
        $this->assertStringContainsString('productDossierState.labelImages.length', $javascript);
        $this->assertStringContainsString('Zonder etiket kun je verder met stap 2', $javascript);
    }
}
