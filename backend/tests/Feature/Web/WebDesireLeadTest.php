<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\Desire;
use App\Modules\Settings\Models\Commune;
use App\Modules\Settings\Models\DynamicList;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use App\Modules\Settings\Models\Wilaya;
use App\Modules\Web\Models\WebLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The public "tell us what you're looking for" (desire) flow: the options
 * endpoint, criteria validation + packing on submit, the labelled inbox
 * payload, and convert turning the criteria into the client's real Desire.
 */
class WebDesireLeadTest extends TestCase
{
    use RefreshDatabase;

    private Wilaya $wilaya;

    private Commune $commune;

    private DynamicListItem $type;

    private DynamicListItem $rooms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wilaya = Wilaya::factory()->create(['code' => '16', 'name' => 'Alger']);
        $this->commune = Commune::factory()->create(['wilaya_id' => $this->wilaya->id, 'name' => 'Hydra']);

        $types = DynamicList::factory()->create(['key' => 'project_types']);
        $roomNumbers = DynamicList::factory()->create(['key' => 'room_numbers']);
        $this->type = DynamicListItem::factory()->create([
            'dynamic_list_id' => $types->id,
            'label' => 'Résidence',
            'label_translations' => ['en' => 'Residence', 'ar' => 'إقامة'],
        ]);
        $this->rooms = DynamicListItem::factory()->create(['dynamic_list_id' => $roomNumbers->id, 'label' => 'F3']);
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

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sara Visitor',
            'phone' => '0550 12 34 56',
            'type' => 'desire',
            'form_token' => Crypt::encryptString((string) now()->subSeconds(30)->timestamp),
            'website' => '',
            'wilaya_ids' => [$this->wilaya->id],
            'commune_ids' => [$this->commune->id],
            'type_ids' => [$this->type->id],
            'room_number_ids' => [$this->rooms->id],
            'budget_min' => 1000000,
            'budget_max' => 2500000,
        ], $overrides);
    }

    public function test_desire_options_exposes_geography_and_vocabularies(): void
    {
        DynamicListItem::factory()->inactive()->create([
            'dynamic_list_id' => $this->type->dynamic_list_id,
            'label' => 'Hidden option',
        ]);

        $this->getJson('/api/v1/public/desire-options')
            ->assertOk()
            ->assertJsonPath('data.wilayas.0.name', 'Alger')
            ->assertJsonPath('data.communes.0.wilaya_id', $this->wilaya->id)
            ->assertJsonPath('data.types.0.label', 'Résidence')
            ->assertJsonPath('data.types.0.labels.en', 'Residence')
            ->assertJsonPath('data.room_numbers.0.label', 'F3')
            ->assertJsonCount(1, 'data.types')
            ->assertJsonStructure(['data' => ['form_token']]);
    }

    public function test_a_desire_submission_packs_only_the_answered_criteria(): void
    {
        $this->postJson('/api/v1/public/leads', $this->payload([
            'commune_ids' => [],
            'budget_min' => null,
        ]))->assertCreated();

        $lead = WebLead::firstOrFail();
        $this->assertSame('desire', $lead->type->value);
        // assertEquals: MySQL's JSON type reorders object keys, and whole
        // floats round-trip as ints.
        $this->assertEquals([
            'wilaya_ids' => [$this->wilaya->id],
            'type_ids' => [$this->type->id],
            'room_number_ids' => [$this->rooms->id],
            'budget_max' => 2500000,
        ], $lead->criteria);
    }

    public function test_a_desire_with_no_criteria_is_still_a_valid_lead(): void
    {
        $this->postJson('/api/v1/public/leads', $this->payload([
            'wilaya_ids' => [], 'commune_ids' => [], 'type_ids' => [],
            'room_number_ids' => [], 'budget_min' => null, 'budget_max' => null,
        ]))->assertCreated();

        $this->assertNull(WebLead::firstOrFail()->criteria);
    }

    public function test_list_item_ids_are_scoped_to_their_vocabulary(): void
    {
        // A room-count id sent as a project type must 422 — and vice versa.
        $this->postJson('/api/v1/public/leads', $this->payload(['type_ids' => [$this->rooms->id]]))
            ->assertUnprocessable();

        $this->postJson('/api/v1/public/leads', $this->payload(['room_number_ids' => [$this->type->id]]))
            ->assertUnprocessable();
    }

    public function test_communes_must_belong_to_the_chosen_wilayas(): void
    {
        $otherWilaya = Wilaya::factory()->create(['code' => '09', 'name' => 'Blida']);
        $foreignCommune = Commune::factory()->create(['wilaya_id' => $otherWilaya->id]);

        $this->postJson('/api/v1/public/leads', $this->payload(['commune_ids' => [$foreignCommune->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('commune_ids');
    }

    public function test_budget_max_below_budget_min_is_rejected(): void
    {
        $this->postJson('/api/v1/public/leads', $this->payload([
            'budget_min' => 3000000,
            'budget_max' => 1000000,
        ]))->assertUnprocessable()->assertJsonValidationErrors('budget_max');
    }

    public function test_the_inbox_resolves_criteria_ids_to_labels(): void
    {
        $this->postJson('/api/v1/public/leads', $this->payload())->assertCreated();

        Sanctum::actingAs($this->userWithPermissions(['web.leads']));

        $this->getJson('/api/v1/web-leads')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'desire')
            ->assertJsonPath('data.0.criteria.wilayas.0', 'Alger')
            ->assertJsonPath('data.0.criteria.communes.0', 'Hydra')
            ->assertJsonPath('data.0.criteria.types.0', 'Residence') // en = test app locale
            ->assertJsonPath('data.0.criteria.room_numbers.0', 'F3')
            ->assertJsonPath('data.0.criteria.budget_max', 2500000);
    }

    public function test_convert_turns_the_criteria_into_the_clients_real_desire(): void
    {
        $this->postJson('/api/v1/public/leads', $this->payload(['message' => 'Near schools please.']))
            ->assertCreated();
        $lead = WebLead::firstOrFail();

        Sanctum::actingAs($this->userWithPermissions(['web.leads', 'clients.create']));

        $clientId = $this->postJson("/api/v1/web-leads/{$lead->id}/convert")
            ->assertOk()
            ->json('client_id');

        $desire = Desire::where('client_id', $clientId)->firstOrFail();
        $this->assertSame('1000000.00', $desire->budget_min);
        $this->assertSame('2500000.00', $desire->budget_max);
        $this->assertStringContainsString('Website desire request', (string) $desire->notes);
        $this->assertStringContainsString('Near schools please.', (string) $desire->notes);
        $this->assertSame([$this->wilaya->id], $desire->wilayas()->pluck('wilayas.id')->all());
        $this->assertSame([$this->commune->id], $desire->communes()->pluck('communes.id')->all());
        $this->assertSame([$this->type->id], $desire->types()->pluck('dynamic_list_items.id')->all());
        $this->assertSame([$this->rooms->id], $desire->roomNumbers()->pluck('dynamic_list_items.id')->all());
    }

    public function test_convert_never_clobbers_an_agent_captured_desire(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['web.leads', 'clients.create', 'clients.view_all']));

        $client = Client::factory()->create(['phone' => '0550 12 34 56']);
        $existing = Desire::factory()->create([
            'client_id' => $client->id,
            'client_project_id' => null,
            'notes' => 'Agent-captured profile.',
        ]);

        $this->postJson('/api/v1/public/leads', $this->payload())->assertCreated();
        $lead = WebLead::firstOrFail();

        // Duplicate phone → link to the existing client explicitly.
        $this->postJson("/api/v1/web-leads/{$lead->id}/convert", ['existing_client_id' => $client->id])
            ->assertOk();

        $this->assertDatabaseCount('desires', 1);
        $this->assertSame('Agent-captured profile.', $existing->fresh()->notes);
    }
}
