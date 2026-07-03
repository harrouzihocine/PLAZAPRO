<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Modules\Clients\Models\Client;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecordActivityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string>  $permissions
     */
    private function user(array $permissions): User
    {
        $role = Role::factory()->create();
        $role->permissions()->sync(
            collect($permissions)->map(fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id)
        );

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_a_records_own_trail_is_readable_with_its_view_permission(): void
    {
        Sanctum::actingAs($this->user(['units.view']));

        $unit = Unit::factory()->create();

        $this->getJson("/api/v1/activity/unit/{$unit->id}")
            ->assertOk()
            ->assertJsonPath('data.0.action', 'create')
            ->assertJsonPath('data.0.subject_id', $unit->id);
    }

    public function test_the_trail_includes_the_acting_users_name(): void
    {
        $actor = $this->user(['units.view']);
        Sanctum::actingAs($actor);

        $unit = Unit::factory()->create();

        $this->getJson("/api/v1/activity/unit/{$unit->id}")
            ->assertOk()
            ->assertJsonPath('data.0.user_name', $actor->name);
    }

    public function test_the_trail_is_refused_without_the_records_view_permission(): void
    {
        $client = Client::factory()->create();

        // Can see inventory, but not clients.
        Sanctum::actingAs($this->user(['units.view']));

        $this->getJson("/api/v1/activity/client/{$client->id}")->assertForbidden();
    }

    public function test_unknown_alias_and_missing_record_both_404(): void
    {
        Sanctum::actingAs($this->user(['units.view']));

        $this->getJson('/api/v1/activity/nope/1')->assertNotFound();
        $this->getJson('/api/v1/activity/unit/999999')->assertNotFound();
    }
}
