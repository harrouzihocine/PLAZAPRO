<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Modules\Inventory\Enums\MediaCollection;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Media;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Public media streaming: only photos/videos/plans of PUBLISHED projects, 404
 * for everything else (never 403 — unpublished must look nonexistent).
 */
class PublicMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
    }

    private function mediaOn(Location $location, array $attributes = []): Media
    {
        $media = Media::factory()->create(array_merge([
            'mediable_type' => 'location',
            'mediable_id' => $location->id,
        ], $attributes));

        Storage::disk('media')->put($media->path, 'fake-image-bytes');

        return $media;
    }

    public function test_photo_on_published_project_streams(): void
    {
        $location = Location::factory()->create(['is_published' => true]);
        $media = $this->mediaOn($location);

        $this->get("/api/v1/public/media/{$media->id}/file")->assertOk();
    }

    public function test_unit_media_follows_its_projects_publish_state(): void
    {
        $published = Location::factory()->create(['is_published' => true]);
        $hidden = Location::factory()->create(['is_published' => false]);
        $unitOnPublished = Unit::factory()->for($published)->create();
        $unitOnHidden = Unit::factory()->for($hidden)->create();

        $ok = Media::factory()->create(['mediable_type' => 'unit', 'mediable_id' => $unitOnPublished->id]);
        $blocked = Media::factory()->create(['mediable_type' => 'unit', 'mediable_id' => $unitOnHidden->id]);
        Storage::disk('media')->put($ok->path, 'x');
        Storage::disk('media')->put($blocked->path, 'x');

        $this->get("/api/v1/public/media/{$ok->id}/file")->assertOk();
        $this->get("/api/v1/public/media/{$blocked->id}/file")->assertNotFound();
    }

    public function test_internal_collections_are_never_served(): void
    {
        $location = Location::factory()->create(['is_published' => true]);

        foreach ([MediaCollection::Documents, MediaCollection::Presentations, MediaCollection::Others] as $collection) {
            $media = $this->mediaOn($location, ['collection' => $collection->value]);
            $this->get("/api/v1/public/media/{$media->id}/file")->assertNotFound();
        }
    }

    public function test_unpublished_or_cancelled_media_is_404(): void
    {
        $hidden = Location::factory()->create(['is_published' => false]);
        $onHidden = $this->mediaOn($hidden);

        $published = Location::factory()->create(['is_published' => true]);
        $cancelled = $this->mediaOn($published, ['status' => 'cancelled']);

        $this->get("/api/v1/public/media/{$onHidden->id}/file")->assertNotFound();
        $this->get("/api/v1/public/media/{$cancelled->id}/file")->assertNotFound();
    }

    public function test_thumb_falls_back_to_the_original_for_photos_and_is_cacheable(): void
    {
        $location = Location::factory()->create(['is_published' => true]);
        $media = $this->mediaOn($location); // photo, no thumb_path yet

        $this->get("/api/v1/public/media/{$media->id}/thumb")
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=86400, public');
    }
}
