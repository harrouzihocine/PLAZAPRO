<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Unavailable" on the public site: the promoteur parks inventory but the
 * showcase keeps showing it, greyed. A parked unit still serves its page (flagged
 * unavailable) and a parked-but-published project still lists (flagged), so the
 * frontend can render the grey treatment — unlike unpublished, which stays hidden.
 */
class PublicAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_parked_unit_still_serves_its_page_flagged_unavailable(): void
    {
        $location = Location::factory()->create(['is_published' => true, 'show_availability' => true]);
        $unit = Unit::factory()->for($location)->unavailable()->create();

        $this->getJson("/api/v1/public/projects/{$location->id}/units/{$unit->id}")
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.unavailable', true)
            ->assertJsonMissingPath('data.sale_status');
    }

    public function test_a_parked_project_still_lists_publicly_flagged_unavailable(): void
    {
        Location::factory()->create(['name' => 'Live', 'is_published' => true, 'is_available' => true]);
        Location::factory()->create(['name' => 'Parked', 'is_published' => true, 'is_available' => false]);

        $data = collect($this->getJson('/api/v1/public/projects')->assertOk()->json('data'));

        // Both published projects appear; the parked one is flagged, not hidden.
        $this->assertCount(2, $data);
        $parked = $data->firstWhere('name', 'Parked');
        $this->assertNotNull($parked);
        $this->assertFalse($parked['is_available']);
        $this->assertTrue($data->firstWhere('name', 'Live')['is_available']);
    }
}
