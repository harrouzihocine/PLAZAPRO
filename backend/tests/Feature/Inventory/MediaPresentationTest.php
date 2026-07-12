<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Media\ImageOptimizer;
use App\Modules\Inventory\Jobs\MakeMediaPreview;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Media;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Presentation mode + renaming: PPTX uploads queue MakeMediaPreview on the
 * media worker, which (besides the PDF preview) rasterizes every slide to a
 * WebP streamed by /media/{id}/slide/{page}; display names are editable via
 * PATCH. The soffice/vips-dependent job run is exercised in-container and
 * skipped on hosts without the binaries (same policy as MediaOptimizationTest).
 */
class MediaPresentationTest extends TestCase
{
    use RefreshDatabase;

    private const PPTX_MIME = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    private ?string $fixtureDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
    }

    protected function tearDown(): void
    {
        if ($this->fixtureDir !== null) {
            File::deleteDirectory($this->fixtureDir);
            $this->fixtureDir = null;
        }
        parent::tearDown();
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

    public function test_pptx_upload_queues_the_preview_job_on_the_media_worker(): void
    {
        Queue::fake();
        $location = Location::factory()->create();
        Sanctum::actingAs($this->editor());

        $this->postJson("/api/v1/locations/{$location->id}/media", [
            'file' => UploadedFile::fake()->create('deck.pptx', 500, self::PPTX_MIME),
        ])->assertCreated();

        Queue::assertPushed(MakeMediaPreview::class, fn ($job) => $job->queue === 'media' && $job->connection === 'media');
    }

    public function test_rename_updates_the_display_name_only(): void
    {
        Queue::fake();
        $location = Location::factory()->create();
        Sanctum::actingAs($this->editor());

        $id = $this->postJson("/api/v1/locations/{$location->id}/media", [
            'file' => UploadedFile::fake()->image('IMG_20260712_183000.jpg'),
        ])->json('data.id');
        $path = Media::findOrFail($id)->path;

        $this->patchJson("/api/v1/media/{$id}", ['original_name' => 'Facade sud.jpg'])
            ->assertOk()
            ->assertJsonPath('data.original_name', 'Facade sud.jpg');

        // Stored file untouched — renaming is pure metadata.
        $this->assertSame($path, Media::findOrFail($id)->path);
        Storage::disk('media')->assertExists($path);
    }

    public function test_rename_requires_media_manage(): void
    {
        Queue::fake();
        $location = Location::factory()->create();
        Sanctum::actingAs($this->editor());
        $id = $this->postJson("/api/v1/locations/{$location->id}/media", [
            'file' => UploadedFile::fake()->image('x.jpg'),
        ])->json('data.id');

        Sanctum::actingAs($this->userWithPermissions(['units.view']));
        $this->patchJson("/api/v1/media/{$id}", ['original_name' => 'nope.jpg'])->assertForbidden();
    }

    public function test_rename_rejects_path_separators(): void
    {
        Queue::fake();
        $location = Location::factory()->create();
        Sanctum::actingAs($this->editor());
        $id = $this->postJson("/api/v1/locations/{$location->id}/media", [
            'file' => UploadedFile::fake()->image('x.jpg'),
        ])->json('data.id');

        $this->patchJson("/api/v1/media/{$id}", ['original_name' => '../../etc/passwd'])
            ->assertStatus(422)->assertJsonValidationErrorFor('original_name');
        $this->patchJson("/api/v1/media/{$id}", ['original_name' => 'a\\b.jpg'])
            ->assertStatus(422)->assertJsonValidationErrorFor('original_name');
    }

    public function test_slide_endpoint_streams_pages_and_bounds_them(): void
    {
        $location = Location::factory()->create();
        Storage::disk('media')->put('slides/deck/0001.webp', 'RIFFxxxxWEBP');
        Storage::disk('media')->put('slides/deck/0002.webp', 'RIFFyyyyWEBP');
        $media = Media::factory()->for($location, 'mediable')->create([
            'type' => 'pptx', 'mime_type' => self::PPTX_MIME,
            'path' => 'uploads/deck.pptx', 'preview_status' => 'ready',
            'slides_path' => 'slides/deck', 'slide_count' => 2,
        ]);

        Sanctum::actingAs($this->userWithPermissions(['units.view']));

        $this->get("/api/v1/media/{$media->id}/slide/1")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');
        $this->get("/api/v1/media/{$media->id}/slide/2")->assertOk();
        $this->get("/api/v1/media/{$media->id}/slide/0")->assertNotFound();
        $this->get("/api/v1/media/{$media->id}/slide/3")->assertNotFound();
    }

    public function test_slide_endpoint_is_404_until_slides_exist_and_requires_units_view(): void
    {
        $location = Location::factory()->create();
        $media = Media::factory()->for($location, 'mediable')->create([
            'type' => 'pptx', 'mime_type' => self::PPTX_MIME,
            'path' => 'uploads/deck.pptx', 'preview_status' => 'pending',
        ]);

        Sanctum::actingAs($this->userWithPermissions(['units.view']));
        $this->get("/api/v1/media/{$media->id}/slide/1")->assertNotFound();

        Sanctum::actingAs($this->userWithPermissions(['media.manage']));
        $this->get("/api/v1/media/{$media->id}/slide/1")->assertForbidden();
    }

    public function test_resource_exposes_one_slide_url_per_page(): void
    {
        $location = Location::factory()->create();
        $media = Media::factory()->for($location, 'mediable')->create([
            'type' => 'pptx', 'mime_type' => self::PPTX_MIME, 'collection' => 'presentations',
            'path' => 'uploads/deck.pptx', 'preview_status' => 'ready',
            'slides_path' => 'slides/deck', 'slide_count' => 3,
        ]);

        Sanctum::actingAs($this->userWithPermissions(['units.view']));

        $data = $this->getJson("/api/v1/locations/{$location->id}/media")
            ->assertOk()
            ->assertJsonPath('data.0.slide_count', 3)
            ->assertJsonCount(3, 'data.0.slide_urls')
            ->json('data.0.slide_urls');

        $this->assertStringContainsString(route('media.slide', ['media' => $media->id, 'page' => 1]), $data[0]);
    }

    public function test_preview_job_renders_the_pdf_the_slides_and_the_thumbnail(): void
    {
        if ($this->convertersMissing()) {
            $this->markTestSkipped('soffice / vips PDF loader not installed on this host — covered in-container.');
        }

        Queue::fake();
        $location = Location::factory()->create();
        Sanctum::actingAs($this->editor());

        $id = $this->postJson("/api/v1/locations/{$location->id}/media", [
            'file' => new UploadedFile($this->makePptxFixture(), 'deck.pptx', self::PPTX_MIME, null, true),
        ])->assertCreated()->json('data.id');

        (new MakeMediaPreview($id))->handle(app(ImageOptimizer::class));

        $media = Media::findOrFail($id);
        $this->assertSame('ready', $media->preview_status);
        $this->assertNotNull($media->preview_path);
        $this->assertSame(3, $media->slide_count); // the fixture has 3 slides
        Storage::disk('media')->assertExists($media->preview_path);
        Storage::disk('media')->assertExists($media->slides_path.'/0001.webp');
        Storage::disk('media')->assertExists($media->slides_path.'/0003.webp');
        Storage::disk('media')->assertExists($media->thumb_path); // first slide = gallery tile

        // A second run is a no-op (idempotent backfill guard).
        $updatedAt = $media->updated_at;
        (new MakeMediaPreview($id))->handle(app(ImageOptimizer::class));
        $this->assertEquals($updatedAt, $media->fresh()->updated_at);

        $this->get("/api/v1/media/{$id}/slide/2")->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get("/api/v1/media/{$id}/slide/4")->assertNotFound();
    }

    public function test_preview_job_marks_failed_when_conversion_is_impossible(): void
    {
        // A missing source file — soffice itself is too forgiving to feed it
        // garbage (it happily converts random bytes as a plain-text document).
        Queue::fake();
        $location = Location::factory()->create();
        $media = Media::factory()->for($location, 'mediable')->create([
            'type' => 'pptx', 'mime_type' => self::PPTX_MIME,
            'path' => 'uploads/does-not-exist.pptx', 'preview_status' => 'pending',
        ]);

        (new MakeMediaPreview($media->id))->handle(app(ImageOptimizer::class));

        $media->refresh();
        $this->assertSame('failed', $media->preview_status);
        $this->assertNull($media->slide_count);
    }

    /** Build a real 3-slide PPTX from the flat-ODP fixture via soffice (removed in tearDown). */
    private function makePptxFixture(): string
    {
        $work = rtrim(sys_get_temp_dir(), '/').'/pptx-fixture-'.Str::uuid();
        @mkdir($work, 0700, true);
        (new Process([
            config('services.libreoffice.bin'), '--headless',
            '-env:UserInstallation=file://'.$work.'/prof',
            '--convert-to', 'pptx', '--outdir', $work,
            base_path('tests/Fixtures/presentation.fodp'),
        ]))->setTimeout(120)->mustRun();

        $this->fixtureDir = $work;

        return $work.'/presentation.pptx';
    }

    private function convertersMissing(): bool
    {
        return trim((string) shell_exec('command -v soffice')) === ''
            || ! str_contains((string) shell_exec('vips -l foreign 2>/dev/null'), 'pdf');
    }
}
