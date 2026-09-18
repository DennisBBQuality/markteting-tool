<?php

namespace Tests\Feature;

use App\Jobs\GenerateProductImages;
use App\Models\ProductImageRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_and_anonymous_users_cannot_reach_generation(): void
    {
        $this->postJson('/api/images/generate')->assertUnauthorized();
        $this->actingAsUser(['actief' => false]);
        $this->postJson('/api/images/generate')->assertUnauthorized();
        $this->assertSame(0, ProductImageRequest::count());
    }

    public function test_model_reads_do_not_consume_generation_allowance(): void
    {
        $this->actingAsUser();
        for ($i = 0; $i < 4; $i++) {
            $this->getJson('/api/images/models')->assertOk();
        }

        // Validation is reached: no upload and no paid job.
        $this->postJson('/api/images/generate')->assertUnprocessable();
    }

    public function test_generation_allowance_is_per_authenticated_user_not_shared_office_ip(): void
    {
        $this->actingAsUser();
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/images/generate')->assertUnprocessable();
        }
        $blocked = $this->postJson('/api/images/generate')->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame((int) $blocked->headers->get('Retry-After'), $blocked->json('retry_after'));
        $this->assertStringContainsString('seconden', $blocked->json('error'));

        $this->actingAsUser();
        $this->postJson('/api/images/generate')->assertUnprocessable();
    }

    public function test_generation_remains_limited_across_sessions_and_resumes_after_one_minute(): void
    {
        $user = $this->actingAsUser();
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/images/generate')->assertUnprocessable();
        }
        $this->app['session']->flush();
        $this->withSession(['userId' => $user->id, 'rol' => $user->rol]);
        $this->postJson('/api/images/generate')->assertStatus(429)->assertHeader('Retry-After');
        $this->travel(61)->seconds();
        $this->postJson('/api/images/generate')->assertUnprocessable();
    }

    public function test_rejected_generation_never_creates_or_dispatches_a_job(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->actingAsUser();
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/images/generate')->assertUnprocessable();
        }
        $this->post('/api/images/generate', [
            'foto' => UploadedFile::fake()->image('test.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(429);
        Queue::assertNotPushed(GenerateProductImages::class);
        $this->assertSame(0, ProductImageRequest::count());
    }

    public function test_all_image_limits_keep_their_budgets_and_cannot_be_bypassed_with_other_assets(): void
    {
        $user = $this->actingAsUser();
        foreach ([
            'image-models' => 30, 'image-model-refresh' => 3, 'image-generation' => 3,
            'image-seo-generation' => 12, 'image-refinement' => 6, 'image-style-library' => 12,
        ] as $name => $attempts) {
            $limiter = RateLimiter::limiter($name);
            $this->assertNotNull($limiter);
            $first = Request::create('/api/images/requests/first/assets/one/seo/generate');
            $second = Request::create('/api/images/requests/second/assets/two/seo/generate');
            foreach ([$first, $second] as $request) {
                $request->attributes->set('authenticatedUser', $user);
            }
            $this->assertSame($attempts, $limiter($first)->maxAttempts);
            $this->assertSame(60, $limiter($first)->decaySeconds);
            $this->assertSame('user:'.$user->id, $limiter($first)->key);
            $this->assertSame($limiter($first)->key, $limiter($second)->key);
        }
    }

    public function test_model_read_limit_is_still_enforced_without_blocking_generation(): void
    {
        $this->actingAsUser();
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/images/models')->assertOk();
        }
        $this->getJson('/api/images/models')->assertStatus(429)->assertHeader('Retry-After');
        Storage::fake('local');
        Queue::fake();
        $this->post('/api/images/generate', [
            'foto' => UploadedFile::fake()->image('test.jpg'),
        ], ['Accept' => 'application/json'])->assertAccepted();
        Queue::assertPushed(GenerateProductImages::class, 1);
        $this->assertSame(1, ProductImageRequest::count());
    }
}
