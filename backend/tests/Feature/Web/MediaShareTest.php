<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Modules\Clients\Models\Client;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Media;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use App\Modules\Web\Models\MediaShare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The WhatsApp media share: minting is scoped to the caller's own clients and
 * to shareable media of the named gallery; the public side serves exactly the
 * share's items — published project or not — until the token expires, and
 * expired/unknown/foreign always read as 404.
 */
class MediaShareTest extends TestCase
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

    private function agent(): User
    {
        return $this->userWithPermissions(['units.view', 'clients.view']);
    }

    private function mediaOn(Location|Unit $mediable, array $attributes = []): Media
    {
        $media = Media::factory()->create(array_merge([
            'mediable_type' => $mediable instanceof Unit ? 'unit' : 'location',
            'mediable_id' => $mediable->id,
        ], $attributes));

        Storage::disk('media')->put($media->path, 'fake-image-bytes');

        return $media;
    }

    private function payload(Location $location, array $mediaIds, Client $client): array
    {
        return [
            'mediable_type' => 'locations',
            'mediable_id' => $location->id,
            'media_ids' => $mediaIds,
            'client_id' => $client->id,
        ];
    }

    public function test_an_agent_mints_a_tokened_link_for_their_own_client(): void
    {
        $user = $this->agent();
        Sanctum::actingAs($user);

        $client = Client::factory()->create(['created_by' => $user->id]);
        // Deliberately UNPUBLISHED: shares work independently of the showcase.
        $location = Location::factory()->create(['is_published' => false]);
        $first = $this->mediaOn($location, ['sort_order' => 1]);
        $second = $this->mediaOn($location, ['sort_order' => 2]);

        // Ids arrive in click order; the stored bundle keeps gallery order.
        $response = $this->postJson('/api/v1/media-shares', $this->payload($location, [$second->id, $first->id], $client))
            ->assertCreated()
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.title', $location->name);

        $token = $response->json('data.token');
        $this->assertStringContainsString("/plaza/share/{$token}", $response->json('data.url'));

        $share = MediaShare::query()->where('token', $token)->firstOrFail();
        $this->assertSame($user->id, $share->user_id);
        $this->assertSame($client->id, $share->client_id);
        $this->assertSame([$first->id, $second->id], $share->media()->pluck('media.id')->all());
    }

    public function test_a_unit_share_is_titled_with_the_units_label(): void
    {
        $user = $this->agent();
        Sanctum::actingAs($user);

        $client = Client::factory()->create(['created_by' => $user->id]);
        $location = Location::factory()->create();
        $unit = Unit::factory()->for($location)->create();
        $media = $this->mediaOn($unit);

        $this->postJson('/api/v1/media-shares', [
            'mediable_type' => 'units',
            'mediable_id' => $unit->id,
            'media_ids' => [$media->id],
            'client_id' => $client->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', $location->name.' — '.$unit->reference);
    }

    public function test_a_client_outside_the_callers_reach_reads_as_absent(): void
    {
        Sanctum::actingAs($this->agent());

        $stranger = Client::factory()->create(); // created_by someone else
        $location = Location::factory()->create();
        $media = $this->mediaOn($location);

        $this->postJson('/api/v1/media-shares', $this->payload($location, [$media->id], $stranger))
            ->assertNotFound();
    }

    public function test_internal_collections_and_foreign_media_are_rejected(): void
    {
        $user = $this->agent();
        Sanctum::actingAs($user);

        $client = Client::factory()->create(['created_by' => $user->id]);
        $location = Location::factory()->create();
        $document = $this->mediaOn($location, ['collection' => 'documents', 'type' => 'pdf']);
        $foreign = $this->mediaOn(Location::factory()->create());

        $this->postJson('/api/v1/media-shares', $this->payload($location, [$document->id], $client))
            ->assertUnprocessable();
        $this->postJson('/api/v1/media-shares', $this->payload($location, [$foreign->id], $client))
            ->assertUnprocessable();
    }

    /** Creates a share through the real endpoint and returns [token, media[]]. */
    private function mintShare(int $mediaCount = 2, bool $published = false): array
    {
        $user = $this->agent();
        Sanctum::actingAs($user);

        $client = Client::factory()->create(['created_by' => $user->id]);
        $location = Location::factory()->create(['is_published' => $published]);
        $media = collect(range(1, $mediaCount))->map(
            fn (int $i) => $this->mediaOn($location, ['sort_order' => $i])
        );

        $token = $this->postJson(
            '/api/v1/media-shares',
            $this->payload($location, $media->pluck('id')->all(), $client),
        )->assertCreated()->json('data.token');

        // The public side is anonymous — drop the authenticated context.
        $this->app['auth']->forgetGuards();

        return [$token, $media, $location];
    }

    public function test_the_share_page_serves_its_items_even_on_an_unpublished_project(): void
    {
        [$token, $media] = $this->mintShare();

        $this->getJson("/api/v1/public/shares/{$token}")
            ->assertOk()
            ->assertJsonCount(2, 'data.media')
            ->assertJsonPath('data.media.0.id', $media[0]->id)
            ->assertJsonPath('data.media.0.file_url', "/api/v1/public/shares/{$token}/media/{$media[0]->id}/file?v=".$media[0]->updated_at->getTimestamp());

        $this->get("/api/v1/public/shares/{$token}/media/{$media[0]->id}/file")->assertOk();
        $this->get("/api/v1/public/shares/{$token}/media/{$media[0]->id}/thumb")
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=86400, public');

        // The regular showcase gate still applies to the plain public routes.
        $this->get("/api/v1/public/media/{$media[0]->id}/file")->assertNotFound();
    }

    public function test_share_streaming_is_scoped_to_the_shares_own_items(): void
    {
        [$token, , $location] = $this->mintShare();

        // Active, shareable, same project — but never picked into this share.
        $outsider = $this->mediaOn($location);

        $this->get("/api/v1/public/shares/{$token}/media/{$outsider->id}/file")->assertNotFound();
    }

    public function test_expired_and_unknown_tokens_read_as_404(): void
    {
        [$token, $media] = $this->mintShare();

        MediaShare::query()->where('token', $token)->update(['expires_at' => now()->subMinute()]);

        $this->getJson("/api/v1/public/shares/{$token}")->assertNotFound();
        $this->get("/api/v1/public/shares/{$token}/media/{$media[0]->id}/file")->assertNotFound();
        $this->getJson('/api/v1/public/shares/'.str_repeat('x', 48))->assertNotFound();
    }

    public function test_cancelled_media_silently_drops_out_of_a_sent_share(): void
    {
        [$token, $media] = $this->mintShare();

        // status is not fillable — mirror what CancelMedia ends up storing.
        $media[1]->forceFill(['status' => 'cancelled'])->save();

        $this->getJson("/api/v1/public/shares/{$token}")
            ->assertOk()
            ->assertJsonCount(1, 'data.media');
        $this->get("/api/v1/public/shares/{$token}/media/{$media[1]->id}/file")->assertNotFound();
    }

    public function test_minting_requires_the_view_permissions(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['units.view'])); // no clients.view

        $location = Location::factory()->create();
        $media = $this->mediaOn($location);
        $client = Client::factory()->create();

        $this->postJson('/api/v1/media-shares', $this->payload($location, [$media->id], $client))
            ->assertForbidden();
    }
}
