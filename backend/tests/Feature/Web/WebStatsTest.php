<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use App\Modules\Web\Models\WebStatEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The website-stats board: web.stats-gated, and the aggregates (unique
 * visitors, per-event totals, top projects/units) add up.
 */
class WebStatsTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $slugs */
    private function userWithPermissions(array $slugs): User
    {
        $role = Role::factory()->create();
        $ids = collect($slugs)->map(
            fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id
        );
        $role->permissions()->sync($ids);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function event(array $attributes): void
    {
        WebStatEvent::create(array_merge([
            'session_key' => str_repeat('a', 32),
            'event' => 'page_view',
            'created_at' => now(),
        ], $attributes));
    }

    public function test_the_board_requires_web_stats(): void
    {
        $this->getJson('/api/v1/web-stats')->assertUnauthorized();

        $this->actingAs($this->userWithPermissions(['web.leads']))
            ->getJson('/api/v1/web-stats')->assertForbidden();

        $this->actingAs($this->userWithPermissions(['web.stats']))
            ->getJson('/api/v1/web-stats')->assertOk();
    }

    public function test_windows_are_whitelisted(): void
    {
        $this->actingAs($this->userWithPermissions(['web.stats']))
            ->getJson('/api/v1/web-stats?days=13')->assertStatus(422);
    }

    public function test_totals_series_and_top_lists_add_up(): void
    {
        $location = Location::factory()->create(['is_published' => true]);
        $unit = Unit::factory()->for($location)->create();

        // Visitor A: two pages today (one project view), mobile.
        $this->event(['session_key' => str_repeat('a', 32), 'is_mobile' => true, 'locale' => 'fr']);
        $this->event(['session_key' => str_repeat('a', 32), 'is_mobile' => true, 'locale' => 'fr']);
        $this->event(['session_key' => str_repeat('a', 32), 'event' => 'project_view', 'location_id' => $location->id]);
        // Visitor B: one page yesterday + a unit view + a WhatsApp click.
        $this->event(['session_key' => str_repeat('b', 32), 'created_at' => now()->subDay(), 'locale' => 'ar']);
        $this->event(['session_key' => str_repeat('b', 32), 'event' => 'unit_view', 'location_id' => $location->id, 'unit_id' => $unit->id]);
        $this->event(['session_key' => str_repeat('b', 32), 'event' => 'whatsapp_click']);
        // Junk id from the wild: counted nowhere in the top lists.
        $this->event(['session_key' => str_repeat('c', 32), 'event' => 'project_view', 'location_id' => 999999]);
        // Outside the window: ignored entirely.
        $this->event(['session_key' => str_repeat('d', 32), 'created_at' => now()->subDays(40)]);

        $data = $this->actingAs($this->userWithPermissions(['web.stats']))
            ->getJson('/api/v1/web-stats?days=30')
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $data['totals']['visits']); // A + B (C never viewed a page)
        $this->assertSame(3, $data['totals']['page_views']);
        $this->assertSame(2, $data['totals']['project_views']);
        $this->assertSame(1, $data['totals']['unit_views']);
        $this->assertSame(1, $data['totals']['whatsapp_clicks']);

        $this->assertCount(30, $data['series']);
        $this->assertSame(2, collect($data['series'])->sum('visits'));
        $this->assertSame(3, collect($data['series'])->sum('page_views'));

        // Top projects: the real project only — the junk id joined to nothing.
        $this->assertCount(1, $data['top_projects']);
        $this->assertSame($location->id, $data['top_projects'][0]['id']);
        $this->assertSame(1, $data['top_projects'][0]['views']);

        $this->assertCount(1, $data['top_units']);
        $this->assertSame($unit->id, $data['top_units'][0]['id']);
        $this->assertSame($unit->reference, $data['top_units'][0]['reference']);

        $this->assertSame(1, $data['devices']['mobile']);
        $this->assertSame(1, $data['devices']['desktop']);
        // assertEquals: a 1-1 tie makes the locale ORDER nondeterministic.
        $this->assertEquals(['fr' => 1, 'ar' => 1], $data['locales']);
    }
}
