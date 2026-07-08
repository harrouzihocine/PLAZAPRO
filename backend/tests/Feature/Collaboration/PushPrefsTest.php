<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration;

use App\Modules\Collaboration\Models\DeviceToken;
use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Collaboration\Notifications\FcmChannel;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Per-category push preferences: the user-facing matrix (PUT /me/push-prefs)
 * and its one enforcement point — DomainNotification::via() dropping the FCM
 * channel for silenced categories. The bell (database) and live broadcast are
 * never filtered, and kinds outside the category map always push.
 */
class PushPrefsTest extends TestCase
{
    use RefreshDatabase;

    /** A user who would receive tray push: FCM configured + device registered. */
    private function pushableUser(?array $prefs = null): User
    {
        config(['services.fcm.credentials' => '/tmp/fake-firebase.json']);

        $user = User::factory()->create(['push_prefs' => $prefs]);
        DeviceToken::create(['user_id' => $user->id, 'token' => 'fcm-'.$user->id, 'platform' => 'android']);

        return $user;
    }

    private function channels(User $user, string $kind): array
    {
        return (new DomainNotification(kind: $kind, title: 'T'))->via($user);
    }

    public function test_defaults_deliver_push_for_every_kind(): void
    {
        $user = $this->pushableUser();

        foreach (['chat_message', 'visit_assigned', 'payment', 'reminder', 'unit_sold', 'project'] as $kind) {
            $this->assertContains(FcmChannel::class, $this->channels($user, $kind), $kind);
        }
    }

    public function test_a_silenced_category_drops_only_the_fcm_channel(): void
    {
        $user = $this->pushableUser(['chat' => false]);

        $channels = $this->channels($user, 'chat_message');

        $this->assertNotContains(FcmChannel::class, $channels);
        // The bell feed and the live badge are untouchable.
        $this->assertContains('database', $channels);
        $this->assertContains('broadcast', $channels);

        // Other categories keep pushing.
        $this->assertContains(FcmChannel::class, $this->channels($user, 'visit_assigned'));
    }

    public function test_every_category_gates_its_kinds(): void
    {
        $allOff = array_fill_keys(array_keys(DomainNotification::PUSH_CATEGORIES), false);
        $user = $this->pushableUser($allOff);

        foreach (DomainNotification::PUSH_CATEGORIES as $kinds) {
            foreach ($kinds as $kind) {
                $this->assertNotContains(FcmChannel::class, $this->channels($user, $kind), $kind);
            }
        }
    }

    public function test_kinds_outside_the_map_always_push(): void
    {
        $allOff = array_fill_keys(array_keys(DomainNotification::PUSH_CATEGORIES), false);
        $user = $this->pushableUser($allOff);

        // Security notices and future kinds must never be silenceable.
        $this->assertContains(FcmChannel::class, $this->channels($user, 'account_locked'));
        $this->assertContains(FcmChannel::class, $this->channels($user, 'some_future_kind'));
    }

    public function test_saving_prefs_merges_and_comes_back_in_the_resource(): void
    {
        Sanctum::actingAs($user = User::factory()->create());

        $this->putJson('/api/v1/me/push-prefs', ['chat' => false, 'listings' => false])
            ->assertOk()
            ->assertJsonPath('data.push_prefs.chat', false)
            ->assertJsonPath('data.push_prefs.listings', false);

        // A later partial update merges over the saved map instead of replacing it.
        $this->putJson('/api/v1/me/push-prefs', ['chat' => true])
            ->assertOk()
            ->assertJsonPath('data.push_prefs.chat', true)
            ->assertJsonPath('data.push_prefs.listings', false);

        $this->assertFalse($user->fresh()->wantsPushFor('unit_sold'));
        $this->assertTrue($user->fresh()->wantsPushFor('chat_message'));
    }

    public function test_unknown_keys_and_non_booleans_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/me/push-prefs', ['chat' => 'loud'])
            ->assertUnprocessable();

        // Unknown keys are simply ignored — never stored.
        $this->putJson('/api/v1/me/push-prefs', ['who_knows' => false])->assertOk();
        $this->assertNull(User::first()->push_prefs['who_knows'] ?? null);
    }

    public function test_chat_push_payload_carries_the_conversation_id_for_quick_reply(): void
    {
        $n = new DomainNotification(
            kind: 'chat_message',
            title: 'Sara',
            body: 'hey',
            link: '/chat/42',
            subjectType: 'conversation',
            subjectId: 42,
        );

        $payload = $n->toFcm(User::factory()->create());

        $this->assertSame('42', $payload['subject_id']);
        $this->assertSame('chat-42', $payload['tag']);
    }
}
