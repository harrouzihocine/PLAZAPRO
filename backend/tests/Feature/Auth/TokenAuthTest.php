<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Stateless Bearer-token auth for mobile / external clients. Unlike the SPA
 * tests, these send NO stateful Origin — the token, not a session cookie,
 * carries the identity.
 */
class TokenAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_obtain_a_token_and_call_the_api_with_it(): void
    {
        $user = User::factory()->create([
            'email' => 'agent@plaza.local',
            'password' => Hash::make('secret-pass'),
        ]);

        $response = $this->postJson('/api/v1/auth/token', [
            'email' => 'agent@plaza.local',
            'password' => 'secret-pass',
            'device_name' => 'Pixel 8 — Field',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email']])
            ->assertJsonPath('user.email', 'agent@plaza.local');

        $token = $response->json('token');
        $this->assertNotEmpty($token);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'Pixel 8 — Field',
        ]);
        $this->assertNotNull($user->fresh()->last_login_at);

        // The token authenticates the stateless /me endpoint.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'agent@plaza.local');
    }

    public function test_token_issuance_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'agent@plaza.local',
            'password' => Hash::make('secret-pass'),
        ]);

        $this->postJson('/api/v1/auth/token', [
            'email' => 'agent@plaza.local',
            'password' => 'wrong',
            'device_name' => 'Pixel 8',
        ])->assertStatus(422);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_inactive_user_cannot_obtain_a_token(): void
    {
        User::factory()->create([
            'email' => 'off@plaza.local',
            'password' => Hash::make('secret-pass'),
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/auth/token', [
            'email' => 'off@plaza.local',
            'password' => 'secret-pass',
            'device_name' => 'Pixel 8',
        ])->assertStatus(422);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_reissuing_for_the_same_device_replaces_the_previous_token(): void
    {
        User::factory()->create([
            'email' => 'agent@plaza.local',
            'password' => Hash::make('secret-pass'),
        ]);

        $payload = [
            'email' => 'agent@plaza.local',
            'password' => 'secret-pass',
            'device_name' => 'Pixel 8',
        ];

        $this->postJson('/api/v1/auth/token', $payload)->assertOk();
        $this->postJson('/api/v1/auth/token', $payload)->assertOk();

        // Same device name => exactly one live token, not two.
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_token_issuance_validates_required_fields(): void
    {
        $this->postJson('/api/v1/auth/token', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password', 'device_name']);
    }

    public function test_a_user_can_revoke_their_current_token(): void
    {
        User::factory()->create([
            'email' => 'agent@plaza.local',
            'password' => Hash::make('secret-pass'),
        ]);

        $token = $this->postJson('/api/v1/auth/token', [
            'email' => 'agent@plaza.local',
            'password' => 'secret-pass',
            'device_name' => 'Pixel 8',
        ])->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/auth/token')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // The revoked token no longer authenticates. Forget the resolved guard
        // so the next request re-reads the (now deleted) token instead of the
        // in-test cached user.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }
}
