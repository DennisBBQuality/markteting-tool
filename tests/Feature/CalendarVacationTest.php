<?php

namespace Tests\Feature;

use App\Models\CalendarItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarVacationTest extends TestCase
{
    use RefreshDatabase;

    private function itemData(): array
    {
        return ['titel' => 'Testmedewerker afwezig', 'type' => 'content',
            'datum_start' => '2026-09-21T09:00', 'datum_eind' => '2026-09-25T18:00',
            'beschrijving' => 'Fictieve kalenderinvoer', 'kleur' => '#3B82F6',
            'project_id' => null, 'link' => null];
    }

    public function test_vacation_can_be_created_reloaded_and_explicitly_unchecked(): void
    {
        $this->actingAsUser();
        $response = $this->postJson('/api/calendar', [...$this->itemData(), 'is_vacation' => true])
            ->assertOk()->assertJsonPath('is_vacation', true);
        $id = $response->json('id');
        $this->getJson('/api/calendar?start=2026-09-21&end=2026-09-28&overlap=1')
            ->assertOk()->assertJsonPath('0.is_vacation', true);
        $this->putJson('/api/calendar/'.$id, [...$this->itemData(), 'is_vacation' => false])
            ->assertOk()->assertJsonPath('is_vacation', false);
        $this->assertSame('Testmedewerker afwezig', CalendarItem::findOrFail($id)->titel);
        $this->assertSame('content', CalendarItem::findOrFail($id)->type);
        $this->assertFalse(CalendarItem::findOrFail($id)->is_vacation);
    }

    public function test_old_clients_keep_explicit_flag_and_legacy_items_remain_unspecified(): void
    {
        $this->actingAsUser();
        $legacy = $this->postJson('/api/calendar', $this->itemData())
            ->assertOk()->assertJsonPath('is_vacation', null)->json('id');
        $this->putJson('/api/calendar/'.$legacy, $this->itemData())
            ->assertOk()->assertJsonPath('is_vacation', null);
        foreach ([true, false] as $flag) {
            $item = CalendarItem::create([...$this->itemData(), 'is_vacation' => $flag]);
            $this->putJson('/api/calendar/'.$item->id, [...$this->itemData(), 'datum_start' => '2026-09-22T09:00'])
                ->assertOk()->assertJsonPath('is_vacation', $flag);
        }
    }

    public function test_invalid_flag_cannot_change_an_item_and_login_is_required(): void
    {
        $this->postJson('/api/calendar', [...$this->itemData(), 'is_vacation' => true])->assertUnauthorized();
        $this->actingAsUser();
        $item = CalendarItem::create($this->itemData());
        $before = $item->fresh()->getRawOriginal();
        $this->postJson('/api/calendar', [...$this->itemData(), 'is_vacation' => 'yes'])->assertUnprocessable();
        $this->putJson('/api/calendar/'.$item->id, [...$this->itemData(), 'is_vacation' => ['true']])->assertUnprocessable();
        $this->assertSame($before, $item->fresh()->getRawOriginal());
        $this->assertDatabaseCount('calendar_items', 1);
    }
}
