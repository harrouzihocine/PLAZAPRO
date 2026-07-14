<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Media;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public unit page: exists only under a published project that advertises
 * availability, carries the unit's own PUBLIC gallery, and leaks nothing about
 * holds or raw sale state (same boundary as the project payload).
 */
class PublicUnitTest extends TestCase
{
    use RefreshDatabase;

    private function publishedUnit(array $locationAttributes = [], array $unitAttributes = []): Unit
    {
        $location = Location::factory()->create(array_merge([
            'is_published' => true,
            'show_availability' => true,
        ], $locationAttributes));

        return Unit::factory()->for($location)->create($unitAttributes);
    }

    public function test_a_unit_of_a_published_project_serves_its_page(): void
    {
        $unit = $this->publishedUnit(unitAttributes: ['price_semi_fini' => 5000000]);

        $this->getJson("/api/v1/public/projects/{$unit->location_id}/units/{$unit->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $unit->id)
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.project.id', $unit->location_id)
            ->assertJsonMissingPath('data.sale_status')
            ->assertJsonMissingPath('data.reserved_project_id');

        $this->assertNotEmpty(
            $this->getJson("/api/v1/public/projects/{$unit->location_id}/units/{$unit->id}")->json('data.form_token'),
        );
    }

    public function test_hidden_projects_and_foreign_units_are_404(): void
    {
        $unpublished = Location::factory()->create(['is_published' => false]);
        $unitOnUnpublished = Unit::factory()->for($unpublished)->create();

        $noAvailability = Location::factory()->create(['is_published' => true, 'show_availability' => false]);
        $unitNoAvailability = Unit::factory()->for($noAvailability)->create();

        $published = Location::factory()->create(['is_published' => true, 'show_availability' => true]);
        $cancelledUnit = Unit::factory()->for($published)->create(['status' => 'cancelled']);
        $foreignUnit = $this->publishedUnit();

        $this->getJson("/api/v1/public/projects/{$unpublished->id}/units/{$unitOnUnpublished->id}")->assertNotFound();
        $this->getJson("/api/v1/public/projects/{$noAvailability->id}/units/{$unitNoAvailability->id}")->assertNotFound();
        $this->getJson("/api/v1/public/projects/{$published->id}/units/{$cancelledUnit->id}")->assertNotFound();
        // A real unit requested under the WRONG project must not resolve.
        $this->getJson("/api/v1/public/projects/{$published->id}/units/{$foreignUnit->id}")->assertNotFound();
    }

    public function test_prices_follow_the_projects_show_prices_switch(): void
    {
        $unit = $this->publishedUnit(['show_prices' => false], ['price_semi_fini' => 4000000, 'price_fini' => 5000000]);

        $response = $this->getJson("/api/v1/public/projects/{$unit->location_id}/units/{$unit->id}")->assertOk();
        $this->assertNull($response->json('data.price_semi_fini'));
        $this->assertNull($response->json('data.price_fini'));
    }

    public function test_a_sold_units_price_is_never_public_even_when_the_project_shows_prices(): void
    {
        $unit = $this->publishedUnit(
            ['show_prices' => true],
            ['price_semi_fini' => 4000000, 'price_fini' => 5000000, 'sale_status' => 'sold'],
        );

        // The unit's own page: gone from the market, price withheld.
        $page = $this->getJson("/api/v1/public/projects/{$unit->location_id}/units/{$unit->id}")->assertOk();
        $this->assertFalse($page->json('data.available'));
        $this->assertNull($page->json('data.price_semi_fini'));
        $this->assertNull($page->json('data.price_fini'));

        // The availability grid embedded in the project payload: same veil,
        // while a free unit on the same project keeps its price.
        $free = Unit::factory()->for($unit->location)->create(['price_semi_fini' => 3000000]);
        $grid = collect($this->getJson("/api/v1/public/projects/{$unit->location_id}")->assertOk()->json('data.units'))
            ->keyBy('id');
        $this->assertNull($grid[$unit->id]['price_semi_fini']);
        $this->assertNotNull($grid[$free->id]['price_semi_fini']);
    }

    public function test_the_gallery_carries_only_public_active_media_of_public_collections(): void
    {
        $unit = $this->publishedUnit();

        $visible = Media::factory()->create(['mediable_type' => 'unit', 'mediable_id' => $unit->id]);
        Media::factory()->create(['mediable_type' => 'unit', 'mediable_id' => $unit->id, 'is_public' => false]);
        Media::factory()->create(['mediable_type' => 'unit', 'mediable_id' => $unit->id, 'status' => 'cancelled']);
        Media::factory()->create(['mediable_type' => 'unit', 'mediable_id' => $unit->id, 'collection' => 'documents']);

        $media = $this->getJson("/api/v1/public/projects/{$unit->location_id}/units/{$unit->id}")
            ->assertOk()
            ->json('data.media');

        $this->assertCount(1, $media);
        $this->assertSame($visible->id, $media[0]['id']);
    }
}
