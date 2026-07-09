<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Support\UnitReference;
use App\Modules\Settings\Models\DynamicList;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The units table's fast bulk tools: multi-select cancel, the CSV
 * export/import round-trip, and the auto-generated compact reference.
 */
class UnitBulkToolsTest extends TestCase
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

    private function manager(): User
    {
        return $this->userWithPermissions(['units.view', 'units.manage']);
    }

    private function csvUpload(string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('units.csv', $contents);
    }

    // ── Bulk cancel ─────────────────────────────────────────────────────

    public function test_bulk_cancel_cancels_available_units_and_skips_sold_ones(): void
    {
        $location = Location::factory()->create();
        $available = Unit::factory()->count(2)->create(['location_id' => $location->id]);
        $sold = Unit::factory()->sold()->create(['location_id' => $location->id]);

        Sanctum::actingAs($this->manager());

        $response = $this->postJson('/api/v1/units/bulk-cancel', [
            'ids' => [...$available->pluck('id'), $sold->id, 999999],
        ])->assertOk();

        $this->assertSame(2, $response->json('data.cancelled'));
        $skipped = collect($response->json('data.skipped'));
        $this->assertCount(2, $skipped);
        $this->assertSame('not_available', $skipped->firstWhere('id', $sold->id)['reason']);
        $this->assertSame('not_found', $skipped->firstWhere('id', 999999)['reason']);

        foreach ($available as $unit) {
            $this->assertDatabaseHas('units', ['id' => $unit->id, 'status' => 'cancelled']);
        }
        $this->assertDatabaseHas('units', ['id' => $sold->id, 'status' => 'active']);
    }

    public function test_bulk_cancel_requires_units_manage(): void
    {
        $unit = Unit::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['units.view']));

        $this->postJson('/api/v1/units/bulk-cancel', ['ids' => [$unit->id]])->assertForbidden();
        $this->assertDatabaseHas('units', ['id' => $unit->id, 'status' => 'active']);
    }

    // ── Export ──────────────────────────────────────────────────────────

    public function test_export_streams_the_filtered_units_as_csv(): void
    {
        $location = Location::factory()->create(['name' => 'Aqua']);
        $unit = Unit::factory()->create(['location_id' => $location->id, 'reference' => 'AQU-F3-A2']);
        $other = Unit::factory()->create(['reference' => 'ELSEWHERE-1']);

        Sanctum::actingAs($this->userWithPermissions(['units.view']));

        $response = $this->get('/api/v1/units/export?location_id='.$location->id)->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('id,location_id,project', $csv); // header row
        $this->assertStringContainsString('AQU-F3-A2', $csv);
        $this->assertStringContainsString('Aqua', $csv);
        $this->assertStringNotContainsString('ELSEWHERE-1', $csv);
    }

    // ── Import ──────────────────────────────────────────────────────────

    public function test_import_updates_specs_in_place_and_versions_price_changes(): void
    {
        $unit = Unit::factory()->create(['price_semi_fini' => '100000.00', 'area_sqm' => '50.00', 'block' => 'A']);

        Sanctum::actingAs($this->manager());

        $csv = "id,area_sqm,price,block\n{$unit->id},80,150000,B\n";
        $response = $this->postJson('/api/v1/units/import', ['file' => $this->csvUpload($csv)])->assertOk();

        $this->assertSame(1, $response->json('data.updated'));
        $this->assertSame(0, $response->json('data.created'));
        $this->assertSame([], $response->json('data.errors'));

        // Specs changed in place; the price change superseded the row (versioned).
        $this->assertDatabaseHas('units', ['id' => $unit->id, 'status' => 'cancelled', 'block' => 'B']);
        $this->assertDatabaseHas('units', [
            'supersedes_id' => $unit->id, 'status' => 'active', 'price_semi_fini' => '150000.00', 'area_sqm' => '80.00',
        ]);
    }

    public function test_import_creates_units_with_auto_generated_deduped_references(): void
    {
        $location = Location::factory()->create(['code' => 'AQUA', 'name' => 'Aqua']);

        Sanctum::actingAs($this->manager());

        // Two identical spec rows without a reference — the second gets -2.
        $csv = "location_id,reference,price,block\n".
            "{$location->id},,200000,A\n".
            "{$location->id},,210000,A\n";
        $response = $this->postJson('/api/v1/units/import', ['file' => $this->csvUpload($csv)])->assertOk();

        $this->assertSame(2, $response->json('data.created'));
        $this->assertDatabaseHas('units', ['location_id' => $location->id, 'reference' => 'AQU-A', 'sale_status' => 'available']);
        $this->assertDatabaseHas('units', ['location_id' => $location->id, 'reference' => 'AQU-A-2']);
    }

    public function test_import_resolves_rooms_and_floor_labels_and_matches_by_project_name(): void
    {
        $location = Location::factory()->create(['name' => 'Perla', 'code' => 'PERLA']);
        $rooms = DynamicListItem::factory()->create([
            'dynamic_list_id' => DynamicList::factory()->create(['key' => 'room_numbers'])->id,
            'label' => 'F3',
        ]);
        $floor = DynamicListItem::factory()->create([
            'dynamic_list_id' => DynamicList::factory()->create(['key' => 'floors'])->id,
            'label' => '2nd Floor',
        ]);

        Sanctum::actingAs($this->manager());

        $csv = "project,reference,rooms,floor,price\nPerla,,F3,2nd Floor,300000\n";
        $this->postJson('/api/v1/units/import', ['file' => $this->csvUpload($csv)])->assertOk();

        $this->assertDatabaseHas('units', [
            'location_id' => $location->id,
            'room_number_id' => $rooms->id,
            'floor_id' => $floor->id,
            'reference' => 'PER-F3-2', // code + rooms + floor
        ]);
    }

    public function test_import_reports_bad_rows_with_line_numbers_and_still_applies_good_ones(): void
    {
        $location = Location::factory()->create();

        Sanctum::actingAs($this->manager());

        $csv = "location_id,reference,price\n".
            "{$location->id},GOOD-1,100000\n".
            "{$location->id},NO-PRICE,\n";
        $response = $this->postJson('/api/v1/units/import', ['file' => $this->csvUpload($csv)])->assertOk();

        $this->assertSame(1, $response->json('data.created'));
        $this->assertSame(3, $response->json('data.errors.0.line'));
        $this->assertDatabaseHas('units', ['reference' => 'GOOD-1']);
        $this->assertDatabaseMissing('units', ['reference' => 'NO-PRICE']);
    }

    public function test_import_rejects_a_duplicate_reference_within_the_project(): void
    {
        $location = Location::factory()->create();
        Unit::factory()->create(['location_id' => $location->id, 'reference' => 'TAKEN']);

        Sanctum::actingAs($this->manager());

        $csv = "location_id,reference,price\n{$location->id},TAKEN,100000\n";
        $response = $this->postJson('/api/v1/units/import', ['file' => $this->csvUpload($csv)])->assertOk();

        $this->assertSame(0, $response->json('data.created'));
        $this->assertCount(1, $response->json('data.errors'));
    }

    public function test_import_sends_one_summary_notification_and_no_per_unit_bells(): void
    {
        $location = Location::factory()->create();
        $teammate = User::factory()->create();
        $manager = $this->manager();

        Sanctum::actingAs($manager);

        $csv = "location_id,reference,price\n".
            "{$location->id},N-1,100000\n".
            "{$location->id},N-2,110000\n";
        $this->postJson('/api/v1/units/import', ['file' => $this->csvUpload($csv)])->assertOk();

        // One units_imported bell — not one unit_published per row.
        $this->assertSame(1, $teammate->notifications()->where('data->kind', 'units_imported')->count());
        $this->assertSame(0, $teammate->notifications()->where('data->kind', 'unit_published')->count());
        $this->assertSame(0, $teammate->notifications()->where('data->kind', 'unit_updated')->count());
    }

    public function test_import_ignores_sale_status_lifecycle_is_not_spreadsheet_editable(): void
    {
        $unit = Unit::factory()->sold()->create();

        Sanctum::actingAs($this->manager());

        $csv = "id,sale_status,block\n{$unit->id},available,Z\n";
        $this->postJson('/api/v1/units/import', ['file' => $this->csvUpload($csv)])->assertOk();

        $this->assertDatabaseHas('units', ['id' => $unit->id, 'sale_status' => 'sold', 'block' => 'Z']);
    }

    public function test_import_requires_units_manage(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['units.view']));

        $this->postJson('/api/v1/units/import', ['file' => $this->csvUpload("id\n")])->assertForbidden();
    }

    // ── Reference generator ─────────────────────────────────────────────

    public function test_unit_reference_builder_is_compact_and_skips_missing_parts(): void
    {
        $location = Location::factory()->make(['code' => 'AQUA-2', 'name' => 'Aqua 2']);

        $this->assertSame(
            'AQU-F3-A2-05',
            UnitReference::build($location, 'F3', '2nd Floor', 'A', null, 5),
        );
        $this->assertSame('AQU-F3+T-B', UnitReference::build($location, 'F3 + T', null, 'b', null, null));
        $this->assertSame('AQU-RDC', UnitReference::build($location, null, 'Ground Floor', null, null, null));
        $this->assertSame('AQU-SS1', UnitReference::build($location, null, 'Sous-sol 1', null, null, null));
        // stack_floor fills in when no floor label is set.
        $this->assertSame('AQU-3', UnitReference::build($location, null, null, null, 3, null));

        $this->assertSame('X-2', UnitReference::dedupe('X', ['x', 'y']));
        $this->assertSame('X-3', UnitReference::dedupe('X', ['X', 'X-2']));
        $this->assertSame('X', UnitReference::dedupe('X', []));
    }
}
