<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Media\ImageOptimizer;
use App\Modules\Inventory\Jobs\OptimizeMedia;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Media;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The media optimization pipeline: uploads mark photos/videos pending and queue
 * OptimizeMedia on the dedicated `media` queue; the thumb endpoint streams the
 * derivative (photos fall back to the original until it exists); the photo job
 * swaps in the WebP and drops the original bytes. ffmpeg-dependent video paths
 * are covered by the image-side contract (same swap/guard code) — encoding
 * itself is exercised against the real binaries in the container.
 */
class MediaOptimizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
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

    private function editor(): User
    {
        return $this->userWithPermissions(['units.view', 'media.manage']);
    }

    public function test_photo_upload_is_marked_pending_and_queues_the_media_job(): void
    {
        Queue::fake();
        $location = Location::factory()->create();
        Sanctum::actingAs($this->editor());

        $this->postJson("/api/v1/locations/{$location->id}/media", [
            'file' => UploadedFile::fake()->image('villa.jpg'),
        ])->assertCreated()
            ->assertJsonPath('data.optimize_status', 'pending');

        Queue::assertPushed(OptimizeMedia::class, fn ($job) => $job->queue === 'media' && $job->connection === 'media');
    }

    public function test_pdf_upload_is_not_optimized(): void
    {
        Queue::fake();
        $location = Location::factory()->create();
        Sanctum::actingAs($this->editor());

        $this->postJson("/api/v1/locations/{$location->id}/media", [
            'file' => UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf'),
        ])->assertCreated()
            ->assertJsonPath('data.optimize_status', null);

        Queue::assertNotPushed(OptimizeMedia::class);
    }

    public function test_thumb_endpoint_falls_back_to_the_original_for_unoptimized_photos(): void
    {
        Queue::fake();
        $location = Location::factory()->create();
        Sanctum::actingAs($this->editor());

        $id = $this->postJson("/api/v1/locations/{$location->id}/media", [
            'file' => UploadedFile::fake()->image('villa.jpg', 40, 30),
        ])->json('data.id');

        $this->get("/api/v1/media/{$id}/thumb")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_video_thumb_url_is_null_until_the_poster_exists(): void
    {
        Queue::fake();
        $location = Location::factory()->create();
        Sanctum::actingAs($this->editor());

        $response = $this->postJson("/api/v1/locations/{$location->id}/media", [
            'file' => UploadedFile::fake()->create('tour.mp4', 500, 'video/mp4'),
        ])->assertCreated()
            ->assertJsonPath('data.thumb_url', null)
            ->assertJsonPath('data.optimize_status', 'pending');

        // Poster ready → thumb_url appears (with its cache-busting version) and streams.
        $media = Media::findOrFail($response->json('data.id'));
        Storage::disk('media')->put('thumbs/poster.webp', 'RIFFxxxxWEBP');
        $media->update(['optimize_status' => 'ready', 'thumb_path' => 'thumbs/poster.webp']);

        $expected = route('media.thumb', $media->id).'?v='.$media->fresh()->updated_at->getTimestamp();
        $this->getJson("/api/v1/locations/{$location->id}/media")
            ->assertJsonPath('data.0.thumb_url', $expected);
        $this->get("/api/v1/media/{$media->id}/thumb")->assertOk();
    }

    public function test_optimize_job_swaps_the_photo_for_a_smaller_webp_and_deletes_the_original(): void
    {
        if ($this->vipsMissing()) {
            $this->markTestSkipped('vipsthumbnail not installed on this host — covered in-container.');
        }

        $location = Location::factory()->create();
        Sanctum::actingAs($this->editor());

        $this->postJson("/api/v1/locations/{$location->id}/media", [
            'file' => UploadedFile::fake()->image('villa.jpg', 3000, 2000),
        ])->assertCreated();

        $media = Media::firstOrFail();
        $originalPath = $media->path;
        $originalSize = $media->size_bytes;

        (new OptimizeMedia($media->id))->handle(app(ImageOptimizer::class), app(\App\Core\Media\VideoOptimizer::class));

        $media->refresh();
        $this->assertSame('ready', $media->optimize_status);
        $this->assertSame('image/webp', $media->mime_type);
        $this->assertStringEndsWith('.webp', $media->path);
        $this->assertSame($originalSize, $media->original_size_bytes);
        $this->assertLessThan($originalSize, $media->size_bytes);
        $this->assertSame(2560, $media->width); // longest edge capped (3000×2000 source)
        $this->assertLessThanOrEqual(2000, $media->height);
        Storage::disk('media')->assertExists($media->path);
        Storage::disk('media')->assertExists($media->thumb_path);
        Storage::disk('media')->assertMissing($originalPath);
    }

    public function test_optimize_job_keeps_the_original_when_the_reencode_is_larger(): void
    {
        if ($this->vipsMissing()) {
            $this->markTestSkipped('vipsthumbnail not installed on this host — covered in-container.');
        }

        $location = Location::factory()->create();

        // Deterministic guard trigger: a real readable image whose recorded
        // size is 1 byte — no re-encode can ever beat it.
        Storage::disk('media')->put(
            'uploads/tight.jpg',
            UploadedFile::fake()->image('tight.jpg', 40, 30)->getContent(),
        );
        $media = Media::factory()->for($location, 'mediable')->create([
            'type' => 'photo', 'mime_type' => 'image/jpeg',
            'path' => 'uploads/tight.jpg', 'size_bytes' => 1, 'optimize_status' => 'pending',
        ]);
        $originalPath = $media->path;

        (new OptimizeMedia($media->id))->handle(app(ImageOptimizer::class), app(\App\Core\Media\VideoOptimizer::class));

        $media->refresh();
        $this->assertSame('ready', $media->optimize_status);
        $this->assertSame($originalPath, $media->path); // untouched
        $this->assertNull($media->original_size_bytes);
        $this->assertNotNull($media->thumb_path); // thumbnail still generated
        Storage::disk('media')->assertExists($originalPath);
    }

    public function test_optimize_job_marks_failed_and_keeps_the_original_when_the_file_is_unreadable(): void
    {
        $location = Location::factory()->create();
        $media = Media::factory()->for($location, 'mediable')->create([
            'type' => 'photo', 'mime_type' => 'image/jpeg',
            'path' => 'uploads/does-not-exist.jpg', 'optimize_status' => 'pending',
        ]);

        (new OptimizeMedia($media->id))->handle(app(ImageOptimizer::class), app(\App\Core\Media\VideoOptimizer::class));

        $this->assertSame('failed', $media->fresh()->optimize_status);
    }

    public function test_download_filename_extension_matches_the_optimized_bytes(): void
    {
        $location = Location::factory()->create();
        Storage::disk('media')->put('uploads/opt.webp', 'RIFFxxxxWEBP');
        $media = Media::factory()->for($location, 'mediable')->create([
            'type' => 'photo', 'mime_type' => 'image/webp',
            'path' => 'uploads/opt.webp', 'original_name' => 'villa.jpg',
        ]);

        Sanctum::actingAs($this->userWithPermissions(['units.view']));

        // The original was villa.jpg but the stored bytes are WebP now — the
        // download must not hand back a .jpg full of WebP.
        $this->get("/api/v1/media/{$media->id}/download")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="villa.webp"');
    }

    private function vipsMissing(): bool
    {
        return trim((string) shell_exec('command -v vipsthumbnail')) === '';
    }
}
