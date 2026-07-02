<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Modules\Inventory\Models\Location;
use App\Modules\Settings\Models\Commune;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use App\Modules\Settings\Models\Wilaya;
use Database\Seeders\WilayaCommuneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WilayaCommuneTest extends TestCase
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

    private function admin(): User
    {
        return $this->userWithPermissions(['settings.manage']);
    }

    public function test_wilayas_and_communes_are_readable_by_any_authenticated_user(): void
    {
        $wilaya = Wilaya::factory()->create(['code' => '16', 'name' => 'Alger']);
        Commune::factory()->for($wilaya)->create(['name' => 'Alger Centre']);

        Sanctum::actingAs($this->userWithPermissions([])); // no settings.manage needed to read

        $this->getJson('/api/v1/wilayas')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Alger')
            ->assertJsonPath('data.0.communes_count', 1);

        $this->getJson("/api/v1/wilayas/{$wilaya->id}/communes")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Alger Centre');
    }

    public function test_writes_require_settings_manage(): void
    {
        $wilaya = Wilaya::factory()->create();

        Sanctum::actingAs($this->userWithPermissions([]));

        $this->postJson('/api/v1/wilayas', ['code' => '99', 'name' => 'Nowhere'])->assertForbidden();
        $this->postJson("/api/v1/wilayas/{$wilaya->id}/communes", ['name' => 'X'])->assertForbidden();
    }

    public function test_admin_can_create_a_wilaya_and_its_communes(): void
    {
        Sanctum::actingAs($this->admin());

        $wilaya = $this->postJson('/api/v1/wilayas', ['code' => '31', 'name' => 'Oran'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Oran')
            ->json('data.id');

        $this->postJson("/api/v1/wilayas/{$wilaya}/communes", ['name' => 'Es Sénia', 'daira_name' => 'Es Sénia'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Es Sénia')
            ->assertJsonPath('data.wilaya_id', $wilaya);
    }

    public function test_a_location_commune_must_belong_to_the_chosen_wilaya(): void
    {
        $wilaya = Wilaya::factory()->create();
        $otherWilaya = Wilaya::factory()->create();
        $foreignCommune = Commune::factory()->for($otherWilaya)->create();

        Sanctum::actingAs($this->userWithPermissions(['locations.manage']));

        $this->postJson('/api/v1/locations', [
            'name' => 'Mismatch', 'code' => 'MIS',
            'wilaya_id' => $wilaya->id, 'commune_id' => $foreignCommune->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('commune_id');
    }

    public function test_wilaya_cancel_is_blocked_while_referenced_by_an_active_location(): void
    {
        $wilaya = Wilaya::factory()->create();
        Location::factory()->create(['wilaya_id' => $wilaya->id]);

        Sanctum::actingAs($this->admin());

        $this->deleteJson("/api/v1/wilayas/{$wilaya->id}")->assertStatus(422);
        $this->assertSame('active', $wilaya->fresh()->getRawOriginal('status'));
    }

    public function test_seeder_loads_the_full_algeria_division(): void
    {
        $this->seed(WilayaCommuneSeeder::class);

        $this->assertSame(58, Wilaya::count());
        $this->assertSame(1541, Commune::count());
    }
}
