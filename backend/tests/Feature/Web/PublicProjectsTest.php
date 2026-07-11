<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Leak-proofing of the anonymous showcase reads: unpublished projects are
 * invisible (list + 404), and the payloads never carry internal fields.
 */
class PublicProjectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_published_projects_are_listed(): void
    {
        $published = Location::factory()->create(['is_published' => true]);
        Location::factory()->create(['is_published' => false]);

        $this->getJson('/api/v1/public/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $published->id);
    }

    public function test_unpublished_or_cancelled_project_detail_is_404(): void
    {
        $unpublished = Location::factory()->create(['is_published' => false]);
        $cancelled = Location::factory()->create(['is_published' => true, 'status' => 'cancelled']);

        $this->getJson("/api/v1/public/projects/{$unpublished->id}")->assertNotFound();
        $this->getJson("/api/v1/public/projects/{$cancelled->id}")->assertNotFound();
        $this->getJson('/api/v1/public/projects/999999')->assertNotFound();
    }

    public function test_public_payload_never_exposes_internal_fields(): void
    {
        $location = Location::factory()->create(['is_published' => true]);
        Unit::factory()->for($location)->create();

        $list = $this->getJson('/api/v1/public/projects')->assertOk();
        $list->assertJsonMissingPath('data.0.code')
            ->assertJsonMissingPath('data.0.gtm_priority')
            ->assertJsonMissingPath('data.0.latitude')
            ->assertJsonMissingPath('data.0.longitude');

        $show = $this->getJson("/api/v1/public/projects/{$location->id}")->assertOk();
        $show->assertJsonMissingPath('data.code')
            ->assertJsonMissingPath('data.gtm_priority')
            ->assertJsonMissingPath('data.units.0.sale_status')
            ->assertJsonMissingPath('data.units.0.reserved_project_id')
            ->assertJsonMissingPath('data.units.0.reserved_expires_at')
            ->assertJsonMissingPath('data.units.0.gtm_priority');
    }

    public function test_show_prices_false_strips_all_prices(): void
    {
        $location = Location::factory()->create(['is_published' => true, 'show_prices' => false]);
        Unit::factory()->for($location)->create(['price_semi_fini' => 1000000]);

        $this->getJson('/api/v1/public/projects')
            ->assertOk()
            ->assertJsonPath('data.0.price_from', null);

        $show = $this->getJson("/api/v1/public/projects/{$location->id}")->assertOk();
        $this->assertNull($show->json('data.units.0.price_semi_fini'));
        $this->assertNull($show->json('data.units.0.price_fini'));
    }

    public function test_show_availability_false_strips_units_and_count(): void
    {
        $location = Location::factory()->create(['is_published' => true, 'show_availability' => false]);
        Unit::factory()->for($location)->create();

        $this->getJson('/api/v1/public/projects')
            ->assertOk()
            ->assertJsonPath('data.0.available_count', null);

        $show = $this->getJson("/api/v1/public/projects/{$location->id}")->assertOk();
        $this->assertNull($show->json('data.units'));
    }

    public function test_available_is_a_strict_boolean_over_sale_status(): void
    {
        $location = Location::factory()->create(['is_published' => true]);
        $available = Unit::factory()->for($location)->create(['reference' => 'AV-1']);
        Unit::factory()->for($location)->interested()->create(['reference' => 'IN-1']);
        Unit::factory()->for($location)->sold()->create(['reference' => 'SO-1']);
        Unit::factory()->for($location)->create(['reference' => 'CA-1', 'status' => 'cancelled']);

        $show = $this->getJson("/api/v1/public/projects/{$location->id}")->assertOk();
        $units = collect($show->json('data.units'));

        $this->assertCount(3, $units); // cancelled unit is gone entirely
        $this->assertTrue($units->firstWhere('reference', 'AV-1')['available']);
        $this->assertFalse($units->firstWhere('reference', 'IN-1')['available']);
        $this->assertFalse($units->firstWhere('reference', 'SO-1')['available']);

        $this->getJson('/api/v1/public/projects')
            ->assertOk()
            ->assertJsonPath('data.0.available_count', 1);

        $this->assertSame($available->id, $units->firstWhere('reference', 'AV-1')['id']);
    }

    public function test_price_from_is_the_cheapest_purchasable_finish(): void
    {
        $location = Location::factory()->create(['is_published' => true]);
        Unit::factory()->for($location)->create(['price_semi_fini' => 3000000]);
        Unit::factory()->for($location)->finiOnly()->create(['price_fini' => 2000000]);
        // Sold units never define the public entry price.
        Unit::factory()->for($location)->sold()->create(['price_semi_fini' => 1000]);

        $this->getJson('/api/v1/public/projects')
            ->assertOk()
            ->assertJsonPath('data.0.price_from', '2000000.00');
    }

    public function test_config_endpoint_is_public_and_carries_a_form_token(): void
    {
        $response = $this->getJson('/api/v1/public/config')->assertOk();

        $this->assertNotEmpty($response->json('data.form_token'));
        $this->assertArrayHasKey('company', $response->json('data'));
        $this->assertArrayHasKey('stats', $response->json('data'));
    }
}
