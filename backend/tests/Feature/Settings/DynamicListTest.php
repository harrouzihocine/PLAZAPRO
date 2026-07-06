<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Modules\Settings\Models\DynamicList;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DynamicListTest extends TestCase
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

    private function item(DynamicList $list, string $value, int $order, bool $active = true): DynamicListItem
    {
        return DynamicListItem::factory()->create([
            'dynamic_list_id' => $list->id,
            'label' => ucfirst($value),
            'value' => $value,
            'sort_order' => $order,
            'is_active' => $active,
        ]);
    }

    public function test_read_endpoint_returns_active_ordered_items_for_any_authenticated_user(): void
    {
        $list = DynamicList::factory()->create(['key' => 'payment_methods']);
        $this->item($list, 'b', 1);
        $this->item($list, 'a', 0);
        $this->item($list, 'hidden', 2, active: false);

        Sanctum::actingAs($this->userWithPermissions([])); // no admin permission needed to read

        $response = $this->getJson('/api/v1/dynamic-lists/payment_methods')->assertOk();

        $response->assertJsonPath('data.key', 'payment_methods');
        $values = collect($response->json('data.items'))->pluck('value')->all();
        $this->assertSame(['a', 'b'], $values); // ordered by sort_order, inactive excluded
    }

    public function test_adding_an_item_makes_it_appear_in_the_dropdown_with_no_code_change(): void
    {
        $list = DynamicList::factory()->system()->create(['key' => 'payment_methods']);
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/dynamic-lists/payment_methods/items', [
            'label' => 'Mobile Money',
            'value' => 'mobile_money',
        ])->assertCreated();

        // A plain user now sees it in the dropdown feed.
        Sanctum::actingAs($this->userWithPermissions([]));
        $values = collect($this->getJson('/api/v1/dynamic-lists/payment_methods')->json('data.items'))
            ->pluck('value')->all();

        $this->assertContains('mobile_money', $values);
    }

    public function test_system_list_cannot_be_renamed_or_removed(): void
    {
        $list = DynamicList::factory()->system()->create(['key' => 'floors', 'name' => 'Floors']);
        Sanctum::actingAs($this->admin());

        $this->putJson('/api/v1/dynamic-lists/floors', ['name' => 'Levels'])->assertStatus(422);
        $this->deleteJson('/api/v1/dynamic-lists/floors')->assertStatus(422);

        $this->assertDatabaseHas('dynamic_lists', ['key' => 'floors', 'name' => 'Floors', 'status' => 'active']);
    }

    public function test_value_must_be_unique_within_a_list(): void
    {
        $list = DynamicList::factory()->create(['key' => 'sources']);
        $this->item($list, 'referral', 0);
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/dynamic-lists/sources/items', [
            'label' => 'Referral (dup)',
            'value' => 'referral',
        ])->assertStatus(422)->assertJsonValidationErrorFor('value');
    }

    public function test_value_is_auto_generated_from_the_label_when_omitted(): void
    {
        $list = DynamicList::factory()->create(['key' => 'payment_methods']);
        Sanctum::actingAs($this->admin());

        // First "Cheque" slugs to "cheque"; the second dedupes to "cheque_2".
        $this->postJson('/api/v1/dynamic-lists/payment_methods/items', ['label' => 'Cheque'])
            ->assertCreated()->assertJsonPath('data.value', 'cheque');

        $this->postJson('/api/v1/dynamic-lists/payment_methods/items', ['label' => 'Cheque'])
            ->assertCreated()->assertJsonPath('data.value', 'cheque_2');
    }

    public function test_reorder_persists_sort_order(): void
    {
        $list = DynamicList::factory()->create(['key' => 'project_types']);
        $a = $this->item($list, 'a', 0);
        $b = $this->item($list, 'b', 1);
        $c = $this->item($list, 'c', 2);
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/dynamic-lists/project_types/items/reorder', [
            'order' => [$c->id, $b->id, $a->id],
        ])->assertOk();

        $values = collect($this->getJson('/api/v1/dynamic-lists/project_types')->json('data.items'))
            ->pluck('value')->all();
        $this->assertSame(['c', 'b', 'a'], $values);
        $this->assertDatabaseHas('dynamic_list_items', ['id' => $c->id, 'sort_order' => 0]);
    }

    public function test_removing_an_item_deactivates_it_without_deleting(): void
    {
        $list = DynamicList::factory()->create(['key' => 'box_types']);
        $item = $this->item($list, 'parking', 0);
        Sanctum::actingAs($this->admin());

        $this->deleteJson("/api/v1/dynamic-lists/box_types/items/{$item->id}")->assertOk();

        // Row survives (no hard delete); it is simply deactivated and dropped from the feed.
        $this->assertDatabaseHas('dynamic_list_items', ['id' => $item->id, 'is_active' => false]);
        Sanctum::actingAs($this->userWithPermissions([]));
        $values = collect($this->getJson('/api/v1/dynamic-lists/box_types')->json('data.items'))
            ->pluck('value')->all();
        $this->assertNotContains('parking', $values);
    }

    public function test_management_endpoints_require_settings_manage_permission(): void
    {
        $list = DynamicList::factory()->create(['key' => 'areas']);
        Sanctum::actingAs($this->userWithPermissions([])); // authenticated, but not an admin

        $this->getJson('/api/v1/dynamic-lists')->assertForbidden();
        $this->postJson('/api/v1/dynamic-lists/areas/items', [
            'label' => 'Blida', 'value' => 'blida',
        ])->assertForbidden();

        // ...but the read endpoint stays open to any authenticated user.
        $this->getJson('/api/v1/dynamic-lists/areas')->assertOk();
    }
}
