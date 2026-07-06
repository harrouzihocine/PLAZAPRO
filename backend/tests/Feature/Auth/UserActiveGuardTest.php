<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Settings\Actions\CancelUser;
use App\Modules\Settings\Actions\SetUserActive;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Access must end the moment a user is deactivated or cancelled — login checks
 * is_active only at sign-in, so a live session/token would otherwise keep working
 * (SEC-3). EnsureUserActive rejects the session; the deactivate/cancel actions
 * drop the API tokens.
 */
class UserActiveGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_active_user_can_call_the_api(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));

        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_a_deactivated_user_is_rejected_mid_session(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')->assertOk();

        // Deactivated after sign-in → the very next request is rejected.
        $user->update(['is_active' => false]);

        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_a_cancelled_user_is_rejected_mid_session(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user);

        $user->cancel('Left the company');

        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_deactivating_a_user_revokes_their_api_tokens(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->createToken('Field phone');
        $this->assertSame(1, $user->tokens()->count());

        app(SetUserActive::class)->handle($user, User::factory()->create(), false);

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_cancelling_a_user_revokes_their_api_tokens(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->createToken('Field phone');

        app(CancelUser::class)->handle($user, User::factory()->create(), 'Left the company');

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }
}
