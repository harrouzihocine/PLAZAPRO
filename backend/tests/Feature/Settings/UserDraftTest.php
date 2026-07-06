<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_mirror_list_and_clear_their_own_draft_metadata(): void
    {
        Sanctum::actingAs(User::factory()->create());

        // Upsert a draft (keys carry colons — the route allows them).
        $this->postJson('/api/v1/me/drafts', [
            'key' => 'call-log:42:client', 'label' => 'Call log — Sami', 'route' => '/clients/42',
        ])->assertOk();

        // Idempotent upsert (same key updates in place).
        $this->postJson('/api/v1/me/drafts', [
            'key' => 'call-log:42:client', 'label' => 'Call log — Sami (edited)', 'route' => '/clients/42',
        ])->assertOk();

        $this->getJson('/api/v1/me/drafts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.label', 'Call log — Sami (edited)');

        $this->deleteJson('/api/v1/me/drafts/'.rawurlencode('call-log:42:client'))->assertOk();
        $this->getJson('/api/v1/me/drafts')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_drafts_are_scoped_to_their_owner(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/me/drafts', ['key' => 'k1', 'label' => 'Mine'])->assertOk();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/me/drafts')->assertOk()->assertJsonCount(0, 'data');
    }
}
