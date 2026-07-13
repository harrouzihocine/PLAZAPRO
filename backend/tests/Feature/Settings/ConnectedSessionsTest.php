<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Modules\Settings\Models\Permission;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Connected sessions: listing the devices an account is signed in on and
 * force-ending them. Session rows are seeded straight into the sessions table
 * (phpunit runs the array session driver; production uses database) — the
 * endpoints only ever read/delete rows, so the seeded shape is the real one.
 */
class ConnectedSessionsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
    private const PLAZA_APK = 'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36 PlazaProNative/2.2.0';

    private function seedSession(User $user, array $overrides = []): string
    {
        $id = $overrides['id'] ?? Str::random(40);

        DB::table('sessions')->insert(array_merge([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '41.100.20.30',
            'user_agent' => self::CHROME_WINDOWS,
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp(),
        ], $overrides));

        return $id;
    }

    /**
     * Authenticate like the SPA (stateful origin) and pin the request's session
     * id by sending the session cookie, so "current session" is deterministic.
     * withCredentials(): json requests drop cookies unless opted in.
     */
    private function actingAsWithSession(User $user): string
    {
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->actingAs($user);
        $this->withSession([]);
        $this->withCredentials();

        $id = $this->app['session']->getId();
        $this->withCookie($this->app['session']->getName(), $id);

        return $id;
    }

    public function test_a_user_sees_their_own_sessions_with_device_info_and_no_raw_ids(): void
    {
        $user = User::factory()->create();
        $desktop = $this->seedSession($user, ['last_activity' => now()->subMinutes(5)->getTimestamp()]);
        $phone = $this->seedSession($user, ['user_agent' => self::PLAZA_APK, 'ip_address' => '192.168.1.50']);

        // Noise the list must not show: someone else's session, an expired one.
        $this->seedSession(User::factory()->create());
        $this->seedSession($user, ['last_activity' => now()->subMinutes((int) config('session.lifetime') + 10)->getTimestamp()]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/me/sessions')->assertOk()->assertJsonCount(2, 'data');

        // Most recent first: the APK phone session.
        $response->assertJsonPath('data.0.is_app', true)
            ->assertJsonPath('data.0.platform', 'Android')
            ->assertJsonPath('data.0.device', 'phone')
            ->assertJsonPath('data.0.ip_address', '192.168.1.50')
            ->assertJsonPath('data.1.browser', 'Chrome')
            ->assertJsonPath('data.1.platform', 'Windows')
            ->assertJsonPath('data.1.device', 'desktop')
            ->assertJsonPath('data.1.is_app', false);

        // A raw session id is the auth cookie itself — only hashes may appear.
        $response->assertDontSee($desktop)->assertDontSee($phone);
        $this->assertSame(hash('sha256', $phone), $response->json('data.0.id'));
    }

    public function test_the_current_session_is_flagged_and_listed_first(): void
    {
        $user = User::factory()->create();
        $currentId = $this->actingAsWithSession($user);

        $this->seedSession($user, ['id' => $currentId, 'last_activity' => now()->subMinutes(30)->getTimestamp()]);
        $this->seedSession($user); // more recent, but not the one in hand

        $this->getJson('/api/v1/me/sessions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.is_current', true)
            ->assertJsonPath('data.0.id', hash('sha256', $currentId))
            ->assertJsonPath('data.1.is_current', false);
    }

    public function test_a_user_can_end_one_of_their_other_sessions(): void
    {
        $user = User::factory()->create();
        $user->setRememberToken('token-before');
        $user->saveQuietly();

        $target = $this->seedSession($user);
        $kept = $this->seedSession($user);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/me/sessions/'.hash('sha256', $target))->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => $target]);
        $this->assertDatabaseHas('sessions', ['id' => $kept]);

        // Remember-me is single-token: it must be cycled or the "ended" device
        // would sign right back in through its remember cookie.
        $this->assertNotSame('token-before', $user->fresh()->remember_token);
        $this->assertDatabaseHas('activity_log', ['action' => 'session_revoked', 'user_id' => $user->id]);
    }

    public function test_the_current_session_cannot_be_ended_from_the_list(): void
    {
        $user = User::factory()->create();
        $currentId = $this->actingAsWithSession($user);
        $this->seedSession($user, ['id' => $currentId]);

        $this->deleteJson('/api/v1/me/sessions/'.hash('sha256', $currentId))
            ->assertUnprocessable();

        $this->assertDatabaseHas('sessions', ['id' => $currentId]);
    }

    public function test_another_users_session_cannot_be_ended(): void
    {
        $stranger = User::factory()->create();
        $strangerSession = $this->seedSession($stranger);

        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/v1/me/sessions/'.hash('sha256', $strangerSession))->assertNotFound();

        $this->assertDatabaseHas('sessions', ['id' => $strangerSession]);
    }

    public function test_ending_other_sessions_keeps_only_the_current_one(): void
    {
        $user = User::factory()->create();
        $currentId = $this->actingAsWithSession($user);

        $this->seedSession($user, ['id' => $currentId]);
        $this->seedSession($user);
        $this->seedSession($user, ['user_agent' => self::PLAZA_APK]);

        $this->deleteJson('/api/v1/me/other-sessions')
            ->assertOk()
            ->assertJsonPath('data.ended', 2);

        $this->assertDatabaseHas('sessions', ['id' => $currentId]);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_changing_the_own_password_ends_every_other_session(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-secret-9')]);
        $currentId = $this->actingAsWithSession($user);

        $this->seedSession($user, ['id' => $currentId]);
        $other = $this->seedSession($user);

        $this->putJson('/api/v1/me/profile', [
            'password' => 'new-secret-10',
            'password_confirmation' => 'new-secret-10',
            'current_password' => 'old-secret-9',
        ])->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => $other]);
        $this->assertDatabaseHas('sessions', ['id' => $currentId]);
    }

    public function test_an_admin_password_reset_ends_all_of_the_targets_sessions(): void
    {
        $role = Role::factory()->create();
        $role->permissions()->sync([
            Permission::firstOrCreate(['slug' => 'users.manage'], ['name' => 'users.manage'])->id,
        ]);
        $admin = User::factory()->create(['role_id' => $role->id]);

        $target = User::factory()->create();
        $target->setRememberToken('token-before');
        $target->saveQuietly();
        $this->seedSession($target);
        $this->seedSession($target);

        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/users/{$target->id}", ['password' => 'fresh-secret-11'])->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
        $this->assertNotSame('token-before', $target->fresh()->remember_token);
    }

    public function test_guests_cannot_list_sessions(): void
    {
        $this->getJson('/api/v1/me/sessions')->assertUnauthorized();
    }
}
