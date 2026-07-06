<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Modules\Clients\Enums\ClientProjectStage;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArchiveOversightTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $slugs */
    private function userWith(array $slugs): User
    {
        $role = Role::factory()->create();
        $role->permissions()->sync(collect($slugs)->map(
            fn (string $s) => Permission::firstOrCreate(['slug' => $s], ['name' => $s])->id
        ));

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function archivedProject(array $attributes, string $reason): ClientProject
    {
        $project = ClientProject::factory()->create($attributes);
        $project->archive($reason);

        return $project->fresh();
    }

    public function test_lists_archived_lost_projects_and_excludes_desire_and_active(): void
    {
        $client = Client::factory()->create();

        $lost = $this->archivedProject(['client_id' => $client->id], 'Too expensive');
        // Archived but parked as a desire — excluded from the archive desk.
        $this->archivedProject(
            ['client_id' => $client->id, 'closed_to_desire_at' => now()],
            'Waiting for stock',
        );
        // Still active — excluded.
        ClientProject::factory()->create(['client_id' => $client->id]);

        Sanctum::actingAs($this->userWith(['oversight.archive']));

        $this->getJson('/api/v1/oversight/archive')
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $lost->id)
            ->assertJsonPath('data.items.0.client', $client->full_name)
            ->assertJsonPath('data.items.0.reason', 'Too expensive');
    }

    public function test_reason_filter_matches_label_including_noted_reasons(): void
    {
        $client = Client::factory()->create();
        $this->archivedProject(['client_id' => $client->id], 'Too expensive');
        $this->archivedProject(['client_id' => $client->id], 'Changed mind — moved abroad');

        Sanctum::actingAs($this->userWith(['oversight.archive']));

        // The label matches its own noted reason via the "label — note" prefix.
        $this->getJson('/api/v1/oversight/archive?reason='.urlencode('Changed mind'))
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.items.0.reason', 'Changed mind — moved abroad');
    }

    public function test_price_and_search_filters_narrow_the_list(): void
    {
        $cheap = Client::factory()->create(['first_name' => 'Ali', 'last_name' => 'Zed']);
        $rich = Client::factory()->create(['first_name' => 'Sami', 'last_name' => 'Boum']);
        $this->archivedProject(['client_id' => $cheap->id, 'total_price' => '1000.00'], 'Lost');
        $this->archivedProject(['client_id' => $rich->id, 'total_price' => '9000.00'], 'Lost');

        Sanctum::actingAs($this->userWith(['oversight.archive']));

        $this->getJson('/api/v1/oversight/archive?min_price=5000')
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.items.0.client', $rich->full_name);

        $this->getJson('/api/v1/oversight/archive?search=Boum')
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.items.0.client', $rich->full_name);
    }

    public function test_summary_breaks_down_by_reason_and_opener(): void
    {
        $opener = User::factory()->create();
        $client = Client::factory()->create();
        $this->archivedProject(['client_id' => $client->id, 'created_by' => $opener->id], 'Too expensive');
        $this->archivedProject(['client_id' => $client->id, 'created_by' => $opener->id], 'Too expensive');
        $this->archivedProject(['client_id' => $client->id, 'created_by' => $opener->id], 'Bad location');

        Sanctum::actingAs($this->userWith(['oversight.archive']));

        $data = $this->getJson('/api/v1/oversight/archive')->assertOk()->json('data');

        $this->assertSame(3, $data['summary']['total']);
        $reasons = collect($data['summary']['by_reason'])->pluck('count', 'label');
        $this->assertSame(2, $reasons['Too expensive']);
        $this->assertSame(1, $reasons['Bad location']);
        $this->assertSame($opener->name, $data['summary']['by_agent'][0]['name']);
        $this->assertSame(3, $data['summary']['by_agent'][0]['count']);
    }

    public function test_requires_the_oversight_archive_permission(): void
    {
        Sanctum::actingAs($this->userWith(['clients.view']));
        $this->getJson('/api/v1/oversight/archive')->assertForbidden();
    }

    public function test_summary_carries_the_archive_count_for_the_sidebar_badge(): void
    {
        $client = Client::factory()->create();
        $this->archivedProject(['client_id' => $client->id], 'Too expensive');
        $this->archivedProject(['client_id' => $client->id], 'Bad location');
        // Parked as a desire, not an active project — excluded, same as the desk itself.
        $this->archivedProject(['client_id' => $client->id, 'closed_to_desire_at' => now()], 'Waiting for stock');

        Sanctum::actingAs($this->userWith(['oversight.archive']));

        $this->getJson('/api/v1/oversight/summary')->assertOk()->assertJsonPath('data.archive', 2);

        // No oversight.archive permission — the key is absent, not zero.
        Sanctum::actingAs($this->userWith(['clients.view']));
        $this->assertArrayNotHasKey('archive', $this->getJson('/api/v1/oversight/summary')->assertOk()->json('data'));
    }

    public function test_reactivating_drops_the_project_off_the_archive(): void
    {
        $client = Client::factory()->create();
        $project = $this->archivedProject(
            ['client_id' => $client->id, 'stage' => ClientProjectStage::Lead->value],
            'Lost',
        );

        Sanctum::actingAs($this->userWith(['oversight.archive', 'projects.manage']));

        $this->getJson('/api/v1/oversight/archive')->assertJsonPath('data.summary.total', 1);

        $this->postJson("/api/v1/projects/{$project->id}/reactivate")->assertOk();

        $this->assertTrue($project->fresh()->isActive());
        $this->getJson('/api/v1/oversight/archive')->assertJsonPath('data.summary.total', 0);
    }

    public function test_export_records_an_audit_entry_and_streams_csv(): void
    {
        $client = Client::factory()->create(['first_name' => 'Nadia', 'last_name' => 'Kaci']);
        $this->archivedProject(['client_id' => $client->id], 'Too expensive');

        Sanctum::actingAs($this->userWith(['oversight.archive']));

        $response = $this->get('/api/v1/oversight/archive/export');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $this->assertStringContainsString($client->full_name, $response->streamedContent());

        $this->assertDatabaseHas('activity_log', ['action' => 'export']);
    }
}
