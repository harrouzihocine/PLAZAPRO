<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mirror the first-party SPA: a stateful origin so Sanctum applies the
        // session middleware to /api routes (cookie auth).
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_a_user_can_log_in_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'agent@plaza.local',
            'password' => Hash::make('secret-pass'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'agent@plaza.local',
            'password' => 'secret-pass',
        ]);

        $response->assertOk()->assertJsonPath('data.email', 'agent@plaza.local');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('activity_log', ['action' => 'login', 'subject_id' => $user->id]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'agent@plaza.local',
            'password' => Hash::make('secret-pass'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'agent@plaza.local',
            'password' => 'wrong',
        ])->assertStatus(422);

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        User::factory()->create([
            'email' => 'off@plaza.local',
            'password' => Hash::make('secret-pass'),
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'off@plaza.local',
            'password' => 'secret-pass',
        ])->assertStatus(422);

        $this->assertGuest();
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_login_validation_rejects_missing_fields(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }
}
