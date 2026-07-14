<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The units import as a SNAPSHOT sync: a re-imported export (id-bearing rows)
 * archives the project's active units that the file no longer lists — but only
 * safe ones (available / unavailable). A live sale (interested / reserved /
 * sold) is reported back, never archived; a pure template/append (no ids)
 * prunes nothing.
 */
class UnitImportSyncTest extends TestCase
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

    public function test_reimport_archives_dropped_available_units_and_keeps_live_sales(): void
    {
        $location = Location::factory()->create();
        // Same price the row carries, so the kept row is a no-op (no supersede).
        $kept = Unit::factory()->create(['location_id' => $location->id, 'reference' => 'KEEP-1', 'price_semi_fini' => '120000.00']);
        $droppedAvailable = Unit::factory()->create(['location_id' => $location->id, 'reference' => 'GONE-1']);
        $droppedParked = Unit::factory()->unavailable()->create(['location_id' => $location->id, 'reference' => 'GONE-2']);
        $droppedSold = Unit::factory()->sold()->create(['location_id' => $location->id, 'reference' => 'SALE-1']);

        Sanctum::actingAs($this->manager());

        // The "edited export" lists only the kept unit (by id). The others vanished.
        $csv = "id,reference,price\n{$kept->id},KEEP-1,120000\n";
        $response = $this->postJson('/api/v1/units/import', ['file' => $this->csvUpload($csv)])->assertOk();

        // Two safe units archived, the sold one reported (never touched).
        $this->assertSame(2, $response->json('data.archived'));
        $skipped = collect($response->json('data.skipped'));
        $this->assertCount(1, $skipped);
        $this->assertSame($droppedSold->id, $skipped->first()['id']);

        $this->assertDatabaseHas('units', ['id' => $kept->id, 'status' => 'active']);
        $this->assertDatabaseHas('units', ['id' => $droppedAvailable->id, 'status' => 'archived']);
        $this->assertDatabaseHas('units', ['id' => $droppedParked->id, 'status' => 'archived']);
        $this->assertDatabaseHas('units', ['id' => $droppedSold->id, 'status' => 'active', 'sale_status' => 'sold']);
    }

    public function test_reimport_never_prunes_across_projects_it_did_not_address(): void
    {
        $addressed = Location::factory()->create();
        $other = Location::factory()->create();

        $keep = Unit::factory()->create(['location_id' => $addressed->id, 'reference' => 'A-KEEP']);
        $dropInAddressed = Unit::factory()->create(['location_id' => $addressed->id, 'reference' => 'A-GONE']);
        $untouchedElsewhere = Unit::factory()->create(['location_id' => $other->id, 'reference' => 'B-1']);

        Sanctum::actingAs($this->manager());

        $csv = "id,reference,price\n{$keep->id},A-KEEP,120000\n";
        $this->postJson('/api/v1/units/import', ['file' => $this->csvUpload($csv)])->assertOk();

        $this->assertDatabaseHas('units', ['id' => $dropInAddressed->id, 'status' => 'archived']);
        // The other project had no rows in the file → untouched.
        $this->assertDatabaseHas('units', ['id' => $untouchedElsewhere->id, 'status' => 'active']);
    }

    public function test_template_style_add_without_ids_prunes_nothing(): void
    {
        $location = Location::factory()->create();
        $existing = Unit::factory()->create(['location_id' => $location->id, 'reference' => 'OLD-1']);

        Sanctum::actingAs($this->manager());

        // A pure "add" file (no id column) is an append, not a snapshot.
        $csv = "location_id,reference,price\n{$location->id},NEW-1,150000\n";
        $response = $this->postJson('/api/v1/units/import', ['file' => $this->csvUpload($csv)])->assertOk();

        $this->assertSame(1, $response->json('data.created'));
        $this->assertSame(0, $response->json('data.archived'));
        $this->assertDatabaseHas('units', ['id' => $existing->id, 'status' => 'active']);
    }

    public function test_a_priced_reimport_versions_the_kept_unit_without_archiving_the_new_version(): void
    {
        // Guards the supersede edge: a price change cancels the row and inserts a
        // new active id — the prune must NOT then archive that fresh version.
        $location = Location::factory()->create();
        $unit = Unit::factory()->create([
            'location_id' => $location->id, 'reference' => 'PX-1', 'price_semi_fini' => '100000.00',
        ]);

        Sanctum::actingAs($this->manager());

        $csv = "id,reference,price\n{$unit->id},PX-1,180000\n";
        $response = $this->postJson('/api/v1/units/import', ['file' => $this->csvUpload($csv)])->assertOk();

        $this->assertSame(1, $response->json('data.updated'));
        $this->assertSame(0, $response->json('data.archived'));
        // Original superseded (cancelled); the replacement stays active, not archived.
        $this->assertDatabaseHas('units', ['id' => $unit->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('units', [
            'supersedes_id' => $unit->id, 'status' => 'active', 'price_semi_fini' => '180000.00',
        ]);
        $this->assertSame(0, Unit::query()->where('status', 'archived')->count());
    }

    public function test_summary_notification_includes_the_archived_count(): void
    {
        $location = Location::factory()->create();
        $teammate = User::factory()->create();
        $keep = Unit::factory()->create(['location_id' => $location->id, 'reference' => 'K-1']);
        Unit::factory()->create(['location_id' => $location->id, 'reference' => 'DROP-1']);

        Sanctum::actingAs($this->manager());

        $csv = "id,reference,price\n{$keep->id},K-1,120000\n";
        $this->postJson('/api/v1/units/import', ['file' => $this->csvUpload($csv)])->assertOk();

        $note = $teammate->notifications()->where('data->kind', 'units_imported')->first();
        $this->assertNotNull($note);
        // The team bell spells out the archived count (the resolved body).
        $this->assertStringContainsString('1 archived', $note->data['body']);
    }
}
