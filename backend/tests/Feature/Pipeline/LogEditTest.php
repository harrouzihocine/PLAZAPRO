<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\Deal;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Payments\Models\Versement;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Pipeline\Models\NextAction;
use App\Modules\Settings\Models\DynamicList;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Editing a past log — the per-workflow edit grants, plus the deal a log opened:
 * a still-waiting one is cancelled with the edit (releasing its units), one that
 * carries money / a sale blocks the edit.
 */
class LogEditTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $slugs */
    private function userWithPermissions(array $slugs): User
    {
        $role = Role::factory()->create();
        $ids = collect($slugs)->map(
            fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id,
        );
        $role->permissions()->sync($ids);

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** A next_action_change_reasons list item id. */
    private function changeReasonId(): int
    {
        $list = DynamicList::firstOrCreate(
            ['key' => 'next_action_change_reasons'],
            ['name' => 'Change Reasons', 'is_system' => true],
        );

        return DynamicListItem::create([
            'dynamic_list_id' => $list->id, 'label' => 'Changed', 'value' => 'changed', 'is_active' => true,
        ])->id;
    }

    /**
     * Log a call that concludes into a deal on a fresh unit.
     *
     * @return array{0: int, 1: ClientProject, 2: Unit, 3: Deal}
     */
    private function callWithDeal(Client $client): array
    {
        $unit = Unit::factory()->create();

        $callId = $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'closure' => ['type' => 'deal', 'units' => [['unit_id' => $unit->id]]],
        ])->assertCreated()->json('data.id');

        $project = ClientProject::query()->where('client_id', $client->id)->sole();
        $deal = Deal::query()->where('call_id', $callId)->sole();

        return [$callId, $project, $unit->fresh(), $deal];
    }

    public function test_editing_a_call_requires_the_edit_call_grant(): void
    {
        $client = Client::factory()->create();
        // Can log, cannot edit.
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'clients.view_all', 'calls.log']));

        $callId = $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'closure' => ['type' => 'desire', 'desire' => ['notes' => 'wants a 3-room']],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/calls/{$callId}/correct", [
            'reason' => 'typo', 'direction' => 'inbound',
        ])->assertForbidden();

        // Grant the edit lever → allowed, and the original is superseded.
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'clients.view_all', 'logs.edit_call']));
        $this->postJson("/api/v1/calls/{$callId}/correct", [
            'reason' => 'fixed direction', 'direction' => 'inbound',
        ])->assertSuccessful()->assertJsonPath('data.direction', 'inbound');

        $this->assertDatabaseHas('calls', ['id' => $callId, 'status' => 'cancelled']);
        $this->assertDatabaseHas('calls', ['supersedes_id' => $callId, 'status' => 'active', 'direction' => 'inbound']);
    }

    public function test_editing_a_log_that_opened_a_waiting_deal_cancels_it_and_releases_the_unit(): void
    {
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions([
            'clients.view', 'clients.view_all', 'calls.log', 'logs.edit_call', 'logs.cancel_deal',
        ]));

        [$callId, $project, $unit, $deal] = $this->callWithDeal($client);

        // The deal reserved the unit off the market (interest hold).
        $this->assertNotSame(SaleStatus::Available->value, $unit->sale_status->value);

        $this->postJson("/api/v1/calls/{$callId}/correct", [
            'reason' => 'wrong client — this deal should not exist', 'direction' => 'inbound',
        ])->assertSuccessful();

        // The deal and its apartment are cancelled (kept as history), the unit is
        // back on the market, and the project stepped back out of the deal stage.
        $this->assertDatabaseHas('deals', ['id' => $deal->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('deal_items', ['deal_id' => $deal->id, 'unit_id' => $unit->id, 'status' => 'cancelled']);
        $this->assertSame(SaleStatus::Available->value, $unit->fresh()->sale_status->value);
        $this->assertSame('negotiating', $project->fresh()->stage->value);
    }

    public function test_editing_a_deal_log_without_the_cancel_deal_grant_is_forbidden(): void
    {
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions([
            'clients.view', 'clients.view_all', 'calls.log', 'logs.edit_call', 'logs.cancel_deal',
        ]));
        [$callId, , , $deal] = $this->callWithDeal($client);

        // A user who may edit calls but not cancel deals cannot edit a log that
        // opened one.
        Sanctum::actingAs($this->userWithPermissions(['clients.view', 'clients.view_all', 'logs.edit_call']));
        $this->postJson("/api/v1/calls/{$callId}/correct", [
            'reason' => 'attempt', 'direction' => 'inbound',
        ])->assertForbidden();

        $this->assertDatabaseHas('deals', ['id' => $deal->id, 'status' => 'active']);
    }

    public function test_editing_a_log_whose_deal_has_a_payment_is_blocked(): void
    {
        $client = Client::factory()->create();
        Sanctum::actingAs($this->userWithPermissions([
            'clients.view', 'clients.view_all', 'calls.log', 'logs.edit_call', 'logs.cancel_deal',
        ]));
        [$callId, $project, $unit, $deal] = $this->callWithDeal($client);

        // A deposit is recorded against the deal's apartment — money on the deal.
        Versement::factory()->create([
            'client_project_id' => $project->id, 'unit_id' => $unit->id, 'amount' => '100000.00',
        ]);

        $this->postJson("/api/v1/calls/{$callId}/correct", [
            'reason' => 'should be blocked', 'direction' => 'inbound',
        ])->assertStatus(422);

        // Nothing changed — the log and the deal are untouched.
        $this->assertDatabaseHas('calls', ['id' => $callId, 'status' => 'active']);
        $this->assertDatabaseHas('deals', ['id' => $deal->id, 'status' => 'active']);
    }

    public function test_editing_the_last_call_repoints_its_open_plan_to_the_new_version(): void
    {
        $agent = User::factory()->agent()->create();
        $client = Client::factory()->create(['assigned_agent_id' => $agent->id]);
        Sanctum::actingAs($this->userWithPermissions([
            'clients.view', 'clients.view_all', 'calls.log', 'logs.edit_call', 'logs.edit_next_action',
        ]));

        $callId = $this->postJson("/api/v1/clients/{$client->id}/calls", [
            'direction' => 'outbound',
            'next_action' => ['type' => 'call', 'due_date' => now()->addDay()->toDateString()],
        ])->assertCreated()->json('data.id');

        $na = NextAction::query()->active()->pending()->sole();
        $this->assertSame($callId, $na->source_id);

        $newCallId = $this->postJson("/api/v1/calls/{$callId}/correct", [
            'reason' => 'fix notes', 'direction' => 'outbound', 'notes' => 'clarified',
        ])->assertSuccessful()->json('data.id');

        // The still-open plan now belongs to the new version, so "this is the last
        // log" stays true across edits — and the plan itself can then be corrected.
        $this->assertSame($newCallId, $na->fresh()->source_id);

        $this->postJson("/api/v1/next-actions/{$na->id}/correct", [
            'reason_id' => $this->changeReasonId(), 'type' => 'office_visit',
            'due_date' => now()->addDays(2)->toDateString(),
        ])->assertSuccessful()->assertJsonPath('data.type', 'office_visit');
    }
}
