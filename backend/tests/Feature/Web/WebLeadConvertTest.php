<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Modules\Clients\Models\Client;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use App\Modules\Web\Models\WebLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The staff inbox: permission gates, one-click convert (client + optional
 * project), and the phone-NSN duplicate flow mirroring the manual client form.
 */
class WebLeadConvertTest extends TestCase
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

    public function test_the_inbox_is_permission_gated(): void
    {
        $lead = WebLead::factory()->create();

        $this->getJson('/api/v1/web-leads')->assertUnauthorized();

        Sanctum::actingAs($this->userWithPermissions(['clients.view']));
        $this->getJson('/api/v1/web-leads')->assertForbidden();

        Sanctum::actingAs($this->userWithPermissions(['web.leads']));
        $this->getJson('/api/v1/web-leads')
            ->assertOk()
            ->assertJsonPath('data.0.id', $lead->id);
    }

    public function test_convert_requires_clients_create_on_top_of_web_leads(): void
    {
        $lead = WebLead::factory()->create();

        Sanctum::actingAs($this->userWithPermissions(['web.leads']));
        $this->postJson("/api/v1/web-leads/{$lead->id}/convert")->assertForbidden();
    }

    public function test_convert_creates_a_client_and_optional_project(): void
    {
        $location = Location::factory()->create(['is_published' => true]);
        $unit = Unit::factory()->for($location)->create();
        $lead = WebLead::factory()->create([
            'phone' => '0550 11 22 33',
            'location_id' => $location->id,
            'unit_id' => $unit->id,
            'message' => 'Call me after 5pm.',
        ]);

        $converter = $this->userWithPermissions(['web.leads', 'clients.create']);
        Sanctum::actingAs($converter);

        $response = $this->postJson("/api/v1/web-leads/{$lead->id}/convert", ['create_project' => true])
            ->assertOk();

        $clientId = $response->json('client_id');
        $this->assertNotNull($clientId);

        $client = Client::findOrFail($clientId);
        $this->assertSame($converter->id, $client->created_by);
        $this->assertStringContainsString('Call me after 5pm.', (string) $client->notes);

        $this->assertDatabaseHas('client_projects', [
            'client_id' => $clientId,
            'location_id' => $location->id,
            'unit_id' => $unit->id,
        ]);

        $this->assertDatabaseHas('web_leads', [
            'id' => $lead->id,
            'lead_status' => 'converted',
            'converted_client_id' => $clientId,
            'handled_by' => $converter->id,
        ]);
    }

    public function test_matching_phone_returns_409_then_links_to_the_existing_client(): void
    {
        $converter = $this->userWithPermissions(['web.leads', 'clients.create', 'clients.view_all']);
        Sanctum::actingAs($converter);

        // Same national significant number, different formatting.
        $existing = Client::factory()->create(['phone' => '+213 550 11 22 33']);
        $lead = WebLead::factory()->create(['phone' => '0550 11 22 33']);

        $this->postJson("/api/v1/web-leads/{$lead->id}/convert")
            ->assertStatus(409)
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('visible', true)
            ->assertJsonPath('client_id', $existing->id);

        $this->postJson("/api/v1/web-leads/{$lead->id}/convert", ['existing_client_id' => $existing->id])
            ->assertOk();

        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseHas('web_leads', [
            'id' => $lead->id,
            'lead_status' => 'converted',
            'converted_client_id' => $existing->id,
        ]);
    }

    public function test_an_invisible_duplicate_is_flagged_without_leaking_the_client(): void
    {
        $owner = $this->userWithPermissions(['clients.create']);
        $existing = Client::factory()->create([
            'phone' => '0550 44 55 66',
            'created_by' => $owner->id,
        ]);

        // No clients.view_all → the owner's client is out of this user's book.
        Sanctum::actingAs($this->userWithPermissions(['web.leads', 'clients.create']));
        $lead = WebLead::factory()->create(['phone' => '+213550445566']);

        $this->postJson("/api/v1/web-leads/{$lead->id}/convert")
            ->assertStatus(409)
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('visible', false)
            ->assertJsonPath('client_id', null);

        $this->assertDatabaseCount('clients', 1);
        $this->assertSame($existing->id, Client::first()->id);
    }

    public function test_a_converted_lead_cannot_be_converted_again(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['web.leads', 'clients.create']));

        $lead = WebLead::factory()->create(['phone' => '0550 77 88 99']);

        $this->postJson("/api/v1/web-leads/{$lead->id}/convert")->assertOk();
        $this->postJson("/api/v1/web-leads/{$lead->id}/convert")->assertUnprocessable();
        $this->assertDatabaseCount('clients', 1);
    }

    public function test_triage_marks_handled_and_spam_but_never_a_converted_lead(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['web.leads', 'clients.create']));

        $lead = WebLead::factory()->create();
        $this->postJson("/api/v1/web-leads/{$lead->id}/handle")
            ->assertOk()
            ->assertJsonPath('data.lead_status', 'handled');

        $spam = WebLead::factory()->create();
        $this->postJson("/api/v1/web-leads/{$spam->id}/spam")
            ->assertOk()
            ->assertJsonPath('data.lead_status', 'spam');

        $converted = WebLead::factory()->create(['phone' => '0561 00 00 00']);
        $this->postJson("/api/v1/web-leads/{$converted->id}/convert")->assertOk();
        $this->postJson("/api/v1/web-leads/{$converted->id}/spam")->assertUnprocessable();
    }
}
