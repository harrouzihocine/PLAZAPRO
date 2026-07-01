<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Jobs\MakeMediaPreview;
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

class MediaTest extends TestCase
{
    use RefreshDatabase;

    private const PPTX_MIME = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

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

    public function test_uploading_a_photo_stores_it_on_the_private_disk_and_records_it(): void
    {
        Queue::fake();
        $location = Location::factory()->create();
        Sanctum::actingAs($this->editor());

        $response = $this->postJson("/api/v1/locations/{$location->id}/media", [
            'file' => UploadedFile::fake()->image('villa.jpg'),
            'collection' => 'gallery',
        ])->assertCreated()->assertJsonPath('data.type', 'photo');

        $path = Media::first()->path;
        Storage::disk('media')->assertExists($path);

        // Randomised name (UUID), original kept as metadata only.
        $this->assertStringNotContainsString('villa', $path);
        $this->assertDatabaseHas('media', [
            'mediable_type' => 'location', 'mediable_id' => $location->id,
            'type' => 'photo', 'original_name' => 'villa.jpg', 'version' => 1,
        ]);
        Queue::assertNotPushed(MakeMediaPreview::class); // photos need no conversion
        $response->assertJsonPath('data.preview_url', $response->json('data.file_url'));
    }

    public function test_executables_are_rejected_by_the_mime_allow_list(): void
    {
        $location = Location::factory()->create();
        Sanctum::actingAs($this->editor());

        $this->postJson("/api/v1/locations/{$location->id}/media", [
            'file' => UploadedFile::fake()->create('malware.php', 8, 'application/x-php'),
        ])->assertStatus(422)->assertJsonValidationErrorFor('file');
    }

    public function test_uploading_a_pptx_queues_a_pdf_preview_job(): void
    {
        Queue::fake();
        $location = Location::factory()->create();
        Sanctum::actingAs($this->editor());

        $this->postJson("/api/v1/locations/{$location->id}/media", [
            'file' => UploadedFile::fake()->create('deck.pptx', 500, self::PPTX_MIME),
        ])->assertCreated()
            ->assertJsonPath('data.type', 'pptx')
            ->assertJsonPath('data.preview_status', 'pending');

        Queue::assertPushed(MakeMediaPreview::class);
    }

    public function test_replacing_a_file_keeps_the_previous_version(): void
    {
        Queue::fake();
        $location = Location::factory()->create();
        Sanctum::actingAs($this->editor());

        $original = $this->postJson("/api/v1/locations/{$location->id}/media", [
            'file' => UploadedFile::fake()->image('v1.jpg'),
        ])->json('data.id');

        // A new version row is created, so the API returns 201 Created.
        $this->postJson("/api/v1/media/{$original}/replace", [
            'file' => UploadedFile::fake()->image('v2.jpg'),
        ])->assertCreated()->assertJsonPath('data.version', 2);

        // Old row kept (cancelled), new row active and linked.
        $this->assertDatabaseHas('media', ['id' => $original, 'status' => 'cancelled']);
        $this->assertDatabaseHas('media', ['supersedes_id' => $original, 'version' => 2, 'status' => 'active']);
        $this->assertSame(2, Media::withoutGlobalScopes()->count());
    }

    public function test_removing_media_cancels_the_row_but_keeps_the_file(): void
    {
        Queue::fake();
        $location = Location::factory()->create();
        Sanctum::actingAs($this->editor());

        $id = $this->postJson("/api/v1/locations/{$location->id}/media", [
            'file' => UploadedFile::fake()->image('keep.jpg'),
        ])->json('data.id');
        $path = Media::find($id)->path;

        $this->deleteJson("/api/v1/media/{$id}")->assertOk();

        $this->assertDatabaseHas('media', ['id' => $id, 'status' => 'cancelled']);
        Storage::disk('media')->assertExists($path); // never physically deleted
    }

    public function test_reorder_sets_sort_order(): void
    {
        Queue::fake();
        $location = Location::factory()->create();
        Sanctum::actingAs($this->editor());

        $a = $this->postJson("/api/v1/locations/{$location->id}/media", ['file' => UploadedFile::fake()->image('a.jpg')])->json('data.id');
        $b = $this->postJson("/api/v1/locations/{$location->id}/media", ['file' => UploadedFile::fake()->image('b.jpg')])->json('data.id');

        $this->postJson("/api/v1/locations/{$location->id}/media/reorder", ['order' => [$b, $a]])->assertOk();

        $this->assertDatabaseHas('media', ['id' => $b, 'sort_order' => 1]);
        $this->assertDatabaseHas('media', ['id' => $a, 'sort_order' => 2]);
    }

    public function test_uploads_require_media_manage(): void
    {
        $location = Location::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['units.view']));

        $this->postJson("/api/v1/locations/{$location->id}/media", [
            'file' => UploadedFile::fake()->image('x.jpg'),
        ])->assertForbidden();
    }

    public function test_streaming_a_file_requires_units_view_and_serves_inline(): void
    {
        Queue::fake();
        $location = Location::factory()->create();
        Sanctum::actingAs($this->editor());
        $id = $this->postJson("/api/v1/locations/{$location->id}/media", [
            'file' => UploadedFile::fake()->image('pic.jpg'),
        ])->json('data.id');

        $response = $this->get("/api/v1/media/{$id}/file");
        $response->assertOk();
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
    }
}
