<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Modules\Settings\Models\AppSetting;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppSettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The remembered() static survives across tests in one PHP process.
        AppSetting::flushRemembered();
    }

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

    public function test_admin_can_save_the_company_office_profile(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['settings.manage']));

        $this->putJson('/api/v1/app-settings', [
            'company_name' => 'Plaza Pro Immobilier',
            'office_address' => '12 Rue des Frères, Alger',
            'office_maps_url' => 'https://maps.google.com/?q=36.75,3.06',
            'office_phone' => '+213 555 00 00 00',
        ])->assertOk();

        $this->assertDatabaseHas('app_settings', ['key' => 'company_name', 'value' => 'Plaza Pro Immobilier']);
        $this->assertDatabaseHas('app_settings', ['key' => 'office_maps_url', 'value' => 'https://maps.google.com/?q=36.75,3.06']);
    }

    public function test_office_profile_is_readable_by_any_authenticated_user(): void
    {
        AppSetting::set('company_name', 'Plaza Pro Immobilier');
        Sanctum::actingAs($this->userWithPermissions([]));

        $this->getJson('/api/v1/app-settings')
            ->assertOk()
            ->assertJsonPath('data.company_name', 'Plaza Pro Immobilier');
    }

    public function test_a_bad_maps_url_is_rejected(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['settings.manage']));

        $this->putJson('/api/v1/app-settings', ['office_maps_url' => 'not-a-url'])
            ->assertStatus(422);
    }

    public function test_saving_the_office_profile_requires_settings_manage(): void
    {
        Sanctum::actingAs($this->userWithPermissions([]));

        $this->putJson('/api/v1/app-settings', ['company_name' => 'Nope'])
            ->assertStatus(403);
    }
}
