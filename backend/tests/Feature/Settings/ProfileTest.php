<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_update_their_own_details(): void
    {
        $user = User::factory()->create(['name' => 'Old Name', 'username' => 'handle']);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/me/profile', [
            'name' => 'New Name',
            'phone' => '0555 12 34 56',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.username', 'handle');

        $this->assertSame('New Name', $user->fresh()->name);
    }

    public function test_a_user_cannot_change_their_own_username_via_profile(): void
    {
        $user = User::factory()->create(['username' => 'handle']);
        Sanctum::actingAs($user);

        // The username is not a fillable field on this endpoint: silently ignored.
        $this->putJson('/api/v1/me/profile', [
            'name' => 'Whoever',
            'username' => 'hacker',
        ])->assertOk();

        $this->assertSame('handle', $user->fresh()->username);
    }

    public function test_changing_the_password_requires_the_current_one(): void
    {
        $user = User::factory()->create(['password' => Hash::make('current-pass')]);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/me/profile', [
            'password' => 'Br4nd-New-Pass!',
            'password_confirmation' => 'Br4nd-New-Pass!',
            'current_password' => 'wrong',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->putJson('/api/v1/me/profile', [
            'password' => 'Br4nd-New-Pass!',
            'password_confirmation' => 'Br4nd-New-Pass!',
            'current_password' => 'current-pass',
        ])->assertOk();

        $this->assertTrue(Hash::check('Br4nd-New-Pass!', $user->fresh()->password));
    }

    public function test_a_user_can_upload_and_then_remove_an_avatar(): void
    {
        Storage::fake('media');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/me/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg', 1200, 900),
        ])
            ->assertOk()
            ->assertJsonPath('data.avatar_url', fn ($url) => is_string($url) && str_contains($url, '/avatar'));

        $path = $user->fresh()->avatar_path;
        $this->assertNotNull($path);
        Storage::disk('media')->assertExists($path);

        // Served through the streaming endpoint (any authed user).
        $this->get("/api/v1/users/{$user->id}/avatar")->assertOk();

        $this->deleteJson('/api/v1/me/avatar')->assertOk();
        $this->assertNull($user->fresh()->avatar_path);
        Storage::disk('media')->assertMissing($path);
    }
}
