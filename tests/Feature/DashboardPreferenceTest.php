<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private function layout(): array
    {
        return array_map(fn ($id) => ['id' => $id, 'width' => 6, 'height' => 280, 'visible' => true], ['calendar', 'tasks', 'projects', 'notes', 'trunkrs', 'notifications']);
    }

    public function test_preferences_require_login(): void
    {
        $this->getJson('/api/dashboard/preferences')->assertUnauthorized();
        $this->putJson('/api/dashboard/preferences', [])->assertUnauthorized();
    }

    public function test_layout_is_saved_only_for_session_user_and_available_after_reload(): void
    {
        $first = $this->actingAsUser();
        $this->getJson('/api/dashboard/preferences')->assertOk()->assertJson(['tiles' => null, 'revision' => 0]);
        $tiles = $this->layout();
        $tiles[1]['visible'] = false;
        $this->putJson('/api/dashboard/preferences', ['tiles' => $tiles, 'revision' => 0, 'user_id' => 'someone-else'])
            ->assertOk()->assertJsonPath('revision', 1);
        $this->getJson('/api/dashboard/preferences')->assertJsonPath('tiles.1.visible', false);
        $this->actingAsUser();
        $this->getJson('/api/dashboard/preferences')->assertJson(['tiles' => null, 'revision' => 0]);
        $this->assertDatabaseCount('dashboard_preferences', 1);
        $this->assertDatabaseHas('dashboard_preferences', ['user_id' => $first->id, 'revision' => 1]);
    }

    public function test_stale_tab_cannot_overwrite_newer_layout(): void
    {
        $this->actingAsUser();
        $data = ['tiles' => $this->layout(), 'revision' => 0];
        $this->putJson('/api/dashboard/preferences', $data)->assertOk();
        $data['tiles'][0]['width'] = 12;
        $this->putJson('/api/dashboard/preferences', $data)->assertConflict();
        $this->getJson('/api/dashboard/preferences')->assertJsonPath('tiles.0.width', 6)->assertJsonPath('revision', 1);
        $data['revision'] = 1;
        $this->putJson('/api/dashboard/preferences', $data)->assertOk()->assertJsonPath('revision', 2);
    }

    public function test_unknown_duplicate_or_invalid_tiles_are_rejected(): void
    {
        $this->actingAsUser();
        foreach ([['id', 'unknown'], ['width', 999], ['height', -1], ['visible', 'yes'], ['script', 'bad']] as [$key, $value]) {
            $tiles = $this->layout();
            $tiles[0][$key] = $value;
            $this->putJson('/api/dashboard/preferences', ['tiles' => $tiles, 'revision' => 0])->assertUnprocessable();
        }
        $tiles = $this->layout();
        $tiles[0]['id'] = 'tasks';
        $this->putJson('/api/dashboard/preferences', ['tiles' => $tiles, 'revision' => 0])->assertUnprocessable();
        $this->assertDatabaseCount('dashboard_preferences', 0);
    }

    public function test_old_tabs_preserve_saved_notification_tile_and_legacy_layout_gains_only_new_tile(): void
    {
        $this->actingAsUser();
        $legacy = array_slice($this->layout(), 0, 5);
        $this->putJson('/api/dashboard/preferences', ['tiles' => $legacy, 'revision' => 0])->assertOk()
            ->assertJsonCount(6, 'tiles')->assertJsonPath('tiles.0.id', 'notifications')->assertJsonPath('tiles.0.visible', true);
        $current = $this->layout();
        $current[5]['visible'] = false;
        $current[5]['width'] = 4;
        $this->putJson('/api/dashboard/preferences', ['tiles' => $current, 'revision' => 1])->assertOk();
        $this->putJson('/api/dashboard/preferences', ['tiles' => $legacy, 'revision' => 2])->assertOk()
            ->assertJsonPath('tiles.5.id', 'notifications')->assertJsonPath('tiles.5.visible', false)->assertJsonPath('tiles.5.width', 4);
        array_shift($current);
        $this->putJson('/api/dashboard/preferences', ['tiles' => $current, 'revision' => 3])->assertUnprocessable();
    }
}
