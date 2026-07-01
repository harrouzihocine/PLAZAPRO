<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Modules\Clients\Models\Client;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditExportTest extends TestCase
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

    public function test_the_audit_feed_can_filter_by_action(): void
    {
        Client::factory()->create(); // records a 'create' entry

        Sanctum::actingAs($this->user(['audit.view']));

        $this->getJson('/api/v1/audit?action=create')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'create');
    }

    public function test_exporting_the_audit_streams_csv_and_records_an_export_entry(): void
    {
        Client::factory()->create();

        Sanctum::actingAs($this->user(['audit.view', 'audit.export']));

        $response = $this->get('/api/v1/audit/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('id,created_at,user_id', $response->streamedContent());

        // The export is itself an auditable action.
        $this->assertDatabaseHas('activity_log', ['action' => 'export']);
    }

    public function test_export_requires_the_export_permission(): void
    {
        // Can view the feed but not export it.
        Sanctum::actingAs($this->user(['audit.view']));

        $this->getJson('/api/v1/audit/export')->assertForbidden();
    }
}
