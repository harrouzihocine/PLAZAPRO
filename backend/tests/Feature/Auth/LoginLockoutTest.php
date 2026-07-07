<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Collaboration\Notifications\DomainNotification;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Brute-force lockout: too many consecutive failed passwords lock the account
 * until an admin unlocks it in Settings → Users (or the optional timed window
 * expires). See AuthController + User::recordFailedLoginAttempt.
 */
class LoginLockoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mirror the first-party SPA (cookie auth), like AuthTest.
        $this->withHeader('Origin', 'http://localhost:5173');

        // The static per-request cache survives across tests in one process.
        AppSetting::flushRemembered();
    }

    private function agent(): User
    {
        return User::factory()->create([
            'email' => 'agent@plaza.local',
            'username' => 'agent',
            'password' => Hash::make('secret-pass'),
        ]);
    }

    private function failLogin(string $login = 'agent'): void
    {
        $this->postJson('/api/v1/auth/login', [
            'login' => $login,
            'password' => 'wrong-guess',
        ])->assertStatus(422);
    }

    public function test_three_wrong_passwords_lock_the_account(): void
    {
        $user = $this->agent();

        $this->failLogin();
        $this->failLogin();
        $this->assertNull($user->fresh()->locked_at);

        $this->failLogin();

        $user->refresh();
        $this->assertNotNull($user->locked_at);
        $this->assertSame(3, $user->failed_login_attempts);
        $this->assertDatabaseHas('activity_log', [
            'action' => 'account_locked',
            'subject_id' => $user->id,
        ]);
    }

    public function test_a_locked_account_refuses_even_the_correct_password(): void
    {
        $user = $this->agent();
        $user->forceFill(['failed_login_attempts' => 3, 'locked_at' => now()])->saveQuietly();

        $this->postJson('/api/v1/auth/login', [
            'login' => 'agent',
            'password' => 'secret-pass',
        ])->assertStatus(422);

        $this->assertGuest();
    }

    public function test_a_successful_login_resets_the_failed_attempt_counter(): void
    {
        $user = $this->agent();

        $this->failLogin();
        $this->failLogin();

        $this->postJson('/api/v1/auth/login', [
            'login' => 'agent',
            'password' => 'secret-pass',
        ])->assertOk();

        $user->refresh();
        $this->assertSame(0, $user->failed_login_attempts);
        $this->assertNull($user->locked_at);
    }

    public function test_the_attempt_limit_is_configurable(): void
    {
        AppSetting::set('login_max_attempts', '2');
        $user = $this->agent();

        $this->failLogin();
        $this->failLogin();

        $this->assertNotNull($user->fresh()->locked_at);
    }

    public function test_a_timed_lockout_expires_on_its_own(): void
    {
        AppSetting::set('login_lockout_minutes', '30');
        $user = $this->agent();
        $user->forceFill(['failed_login_attempts' => 3, 'locked_at' => now()])->saveQuietly();

        // Still inside the window: refused.
        $this->postJson('/api/v1/auth/login', [
            'login' => 'agent',
            'password' => 'secret-pass',
        ])->assertStatus(422);

        $this->travel(31)->minutes();

        $this->postJson('/api/v1/auth/login', [
            'login' => 'agent',
            'password' => 'secret-pass',
        ])->assertOk();

        $user->refresh();
        $this->assertNull($user->locked_at);
        $this->assertSame(0, $user->failed_login_attempts);
    }

    public function test_the_token_endpoint_counts_and_refuses_locked_accounts_too(): void
    {
        $user = $this->agent();

        foreach (range(1, 3) as $i) {
            $this->postJson('/api/v1/auth/token', [
                'email' => 'agent@plaza.local',
                'password' => 'wrong-guess',
                'device_name' => 'Pixel 8',
            ])->assertStatus(422);
        }

        $this->assertNotNull($user->fresh()->locked_at);

        // Correct password, still refused — and no token issued.
        $this->postJson('/api/v1/auth/token', [
            'email' => 'agent@plaza.local',
            'password' => 'secret-pass',
            'device_name' => 'Pixel 8',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    /** @param  list<string>  $slugs */
    private function userWithPermissions(array $slugs): User
    {
        $role = Role::factory()->create();
        $role->permissions()->sync(collect($slugs)->map(
            fn (string $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug])->id,
        ));

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_a_users_unlock_holder_can_unlock_the_account(): void
    {
        $user = $this->agent();
        $user->forceFill(['failed_login_attempts' => 3, 'locked_at' => now()])->saveQuietly();

        Sanctum::actingAs($this->userWithPermissions(['users.unlock']));

        $this->putJson("/api/v1/users/{$user->id}/unlock")
            ->assertOk()
            ->assertJsonPath('data.locked_at', null);

        $user->refresh();
        $this->assertNull($user->locked_at);
        $this->assertSame(0, $user->failed_login_attempts);
        $this->assertDatabaseHas('activity_log', [
            'action' => 'account_unlocked',
            'subject_id' => $user->id,
        ]);
    }

    public function test_unlock_requires_the_users_unlock_permission(): void
    {
        $user = $this->agent();
        $user->forceFill(['failed_login_attempts' => 3, 'locked_at' => now()])->saveQuietly();

        Sanctum::actingAs(User::factory()->create());
        $this->putJson("/api/v1/users/{$user->id}/unlock")->assertForbidden();

        // users.manage alone is NOT enough — unlocking is its own grant.
        Sanctum::actingAs($this->userWithPermissions(['users.manage']));
        $this->putJson("/api/v1/users/{$user->id}/unlock")->assertForbidden();
    }

    public function test_a_lock_never_expires_by_default(): void
    {
        $user = $this->agent();
        $user->forceFill(['failed_login_attempts' => 3, 'locked_at' => now()])->saveQuietly();

        $this->travel(365)->days();

        $this->postJson('/api/v1/auth/login', [
            'login' => 'agent',
            'password' => 'secret-pass',
        ])->assertStatus(422);

        $this->assertGuest();
        $this->assertNotNull($user->fresh()->locked_at);
    }

    public function test_unlocking_an_account_that_is_not_locked_is_rejected(): void
    {
        $user = $this->agent();

        Sanctum::actingAs($this->userWithPermissions(['users.unlock']));

        $this->putJson("/api/v1/users/{$user->id}/unlock")->assertStatus(422);
    }

    public function test_locking_notifies_unlock_holders_and_super_admins_only(): void
    {
        Notification::fake();

        $unlocker = $this->userWithPermissions(['users.unlock']);
        $superAdmin = User::factory()->create([
            'role_id' => Role::factory()->create(['slug' => 'super-admin'])->id,
        ]);
        $bystander = User::factory()->create();
        $user = $this->agent();

        $this->failLogin();
        $this->failLogin();
        Notification::assertNothingSent();

        $this->failLogin(); // third strike locks and alerts

        $expected = fn (DomainNotification $n) => $n->kind === 'account_locked'
            && $n->link === '/settings/users'
            && $n->subjectId === $user->id;

        Notification::assertSentTo($unlocker, DomainNotification::class, $expected);
        Notification::assertSentTo($superAdmin, DomainNotification::class, $expected);
        Notification::assertNotSentTo($bystander, DomainNotification::class);
        Notification::assertNotSentTo($user, DomainNotification::class);
    }

    public function test_the_console_command_unlocks_an_account(): void
    {
        $user = $this->agent();
        $user->forceFill(['failed_login_attempts' => 3, 'locked_at' => now()])->saveQuietly();

        $this->artisan('user:unlock', ['login' => 'agent'])
            ->expectsOutputToContain('unlocked')
            ->assertSuccessful();

        $this->assertNull($user->fresh()->locked_at);
    }
}
