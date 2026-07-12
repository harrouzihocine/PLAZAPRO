<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Media;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Settings\Models\Commune;
use App\Modules\Web\Models\WebsiteSpace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Round-two config surface: the multi-number contact list, the extra social
 * links, the communes stat, the curated hero slideshow from the site's own
 * library, and the per-media publish switch end to end.
 */
class PublicConfigRoundTwoTest extends TestCase
{
    use RefreshDatabase;

    public function test_phones_split_into_a_list_and_fall_back_to_the_office_line(): void
    {
        AppSetting::set('office_phone', '021 11 22 33');

        $company = $this->getJson('/api/v1/public/config')->json('data.company');
        $this->assertSame(['021 11 22 33'], $company['phones']);

        AppSetting::set('website_phones', "0550 11 22 33\n0660 44 55 66");
        $company = $this->getJson('/api/v1/public/config')->json('data.company');
        $this->assertSame(['0550 11 22 33', '0660 44 55 66'], $company['phones']);
        $this->assertSame('0550 11 22 33', $company['phone']);
    }

    public function test_all_social_links_are_emitted(): void
    {
        AppSetting::set('website_tiktok_url', 'https://tiktok.com/@plaza');
        AppSetting::set('website_youtube_url', 'https://youtube.com/@plaza');

        $company = $this->getJson('/api/v1/public/config')->json('data.company');
        $this->assertSame('https://tiktok.com/@plaza', $company['tiktok_url']);
        $this->assertSame('https://youtube.com/@plaza', $company['youtube_url']);
        $this->assertArrayHasKey('linkedin_url', $company);
        $this->assertArrayHasKey('x_url', $company);
    }

    public function test_stats_count_distinct_communes_of_published_projects(): void
    {
        [$first, $second, $third] = Commune::factory()->count(3)->create();

        Location::factory()->create(['is_published' => true, 'commune_id' => $first->id]);
        Location::factory()->create(['is_published' => true, 'commune_id' => $first->id]);
        Location::factory()->create(['is_published' => true, 'commune_id' => $second->id]);
        Location::factory()->create(['is_published' => false, 'commune_id' => $third->id]);

        $stats = $this->getJson('/api/v1/public/config')->json('data.stats');
        $this->assertSame(2, $stats['communes']);
    }

    public function test_hero_library_photos_make_an_ordered_slideshow(): void
    {
        $space = WebsiteSpace::hero();
        $first = Media::factory()->create(['mediable_type' => 'website_space', 'mediable_id' => $space->id]);
        $second = Media::factory()->create(['mediable_type' => 'website_space', 'mediable_id' => $space->id]);
        $hiddenPick = Media::factory()->create([
            'mediable_type' => 'website_space', 'mediable_id' => $space->id, 'is_public' => false,
        ]);

        // Owner order (second first) is preserved; the unticked pick drops out.
        AppSetting::set('website_hero_media_ids', "{$second->id},{$first->id},{$hiddenPick->id}");

        $hero = $this->getJson('/api/v1/public/config')->json('data.hero');
        $this->assertSame('slideshow', $hero['type']);
        $this->assertCount(2, $hero['slides']);
        $this->assertStringContainsString("/{$second->id}/", $hero['slides'][0]['image_url']);
        $this->assertStringContainsString("/{$first->id}/", $hero['slides'][1]['image_url']);

        // A single surviving photo degrades to the plain photo hero.
        AppSetting::set('website_hero_media_ids', (string) $first->id);
        $this->assertSame('photo', $this->getJson('/api/v1/public/config')->json('data.hero')['type']);
    }

    public function test_a_leading_library_video_becomes_the_video_hero(): void
    {
        $space = WebsiteSpace::hero();
        $video = Media::factory()->create([
            'mediable_type' => 'website_space', 'mediable_id' => $space->id,
            'collection' => 'videos', 'type' => 'video', 'mime_type' => 'video/mp4',
        ]);

        AppSetting::set('website_hero_media_ids', (string) $video->id);

        $hero = $this->getJson('/api/v1/public/config')->json('data.hero');
        $this->assertSame('video', $hero['type']);
        $this->assertNotEmpty($hero['video_url']);
    }

    public function test_unticked_media_leaves_the_project_payload_and_stops_streaming(): void
    {
        Storage::fake('media');

        $location = Location::factory()->create(['is_published' => true]);
        $visible = Media::factory()->create(['mediable_type' => 'location', 'mediable_id' => $location->id]);
        $hidden = Media::factory()->create([
            'mediable_type' => 'location', 'mediable_id' => $location->id, 'is_public' => false,
        ]);
        Storage::disk('media')->put($visible->path, 'x');
        Storage::disk('media')->put($hidden->path, 'x');

        $media = $this->getJson("/api/v1/public/projects/{$location->id}")->assertOk()->json('data.media');
        $this->assertSame([$visible->id], array_column($media, 'id'));

        $this->get("/api/v1/public/media/{$visible->id}/file")->assertOk();
        $this->get("/api/v1/public/media/{$hidden->id}/file")->assertNotFound();
    }
}
