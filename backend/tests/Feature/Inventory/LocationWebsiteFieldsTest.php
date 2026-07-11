<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Location;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The per-project website controls (publish/prices/availability + trilingual
 * marketing copy) ride the existing locations.manage write gate.
 */
class LocationWebsiteFieldsTest extends TestCase
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

    public function test_manager_round_trips_the_website_fields(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['units.view', 'locations.manage']));

        $location = Location::factory()->create();

        $this->putJson("/api/v1/locations/{$location->id}", [
            'is_published' => true,
            'show_prices' => false,
            'show_availability' => true,
            'marketing_tagline' => ['fr' => 'Vivre face à la mer', 'ar' => 'عيش قرب البحر'],
            'marketing_description' => ['fr' => 'Une résidence gardée…'],
            'construction_progress' => 65,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_published', true)
            ->assertJsonPath('data.show_prices', false)
            ->assertJsonPath('data.marketing_tagline.fr', 'Vivre face à la mer')
            ->assertJsonPath('data.construction_progress', 65);
    }

    public function test_tagline_length_and_progress_range_are_validated(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['units.view', 'locations.manage']));
        $location = Location::factory()->create();

        $this->putJson("/api/v1/locations/{$location->id}", [
            'marketing_tagline' => ['fr' => str_repeat('x', 181)],
        ])->assertUnprocessable();

        $this->putJson("/api/v1/locations/{$location->id}", [
            'construction_progress' => 101,
        ])->assertUnprocessable();
    }

    public function test_non_manager_cannot_touch_website_fields(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['units.view']));
        $location = Location::factory()->create();

        $this->putJson("/api/v1/locations/{$location->id}", ['is_published' => true])
            ->assertForbidden();

        $this->assertDatabaseHas('locations', ['id' => $location->id, 'is_published' => false]);
    }
}
