<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Modules\Analytics\Models\ActivityLog;
use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_is_logged_automatically(): void
    {
        $role = Role::factory()->create();

        $log = ActivityLog::query()->latest('id')->first();

        $this->assertSame('create', $log->action);
        $this->assertSame(Role::class, $log->subject_type);
        $this->assertSame($role->id, $log->subject_id);
    }

    public function test_update_records_before_and_after(): void
    {
        $role = Role::factory()->create(['name' => 'Old']);

        $role->update(['name' => 'New']);

        $log = ActivityLog::query()->where('action', 'update')->latest('id')->first();
        $this->assertSame('New', $log->changes['after']['name']);
        $this->assertSame('Old', $log->changes['before']['name']);
    }

    public function test_cancel_is_logged_as_cancel_action(): void
    {
        $role = Role::factory()->create();

        $role->cancel('merged');

        $this->assertDatabaseHas('activity_log', [
            'action' => 'cancel',
            'subject_type' => Role::class,
            'subject_id' => $role->id,
        ]);
    }

    public function test_password_is_never_written_to_the_audit_log(): void
    {
        $user = User::factory()->create();

        $log = ActivityLog::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $user->id)
            ->where('action', 'create')
            ->first();

        $this->assertArrayNotHasKey('password', $log->changes['after'] ?? []);
        $this->assertArrayNotHasKey('remember_token', $log->changes['after'] ?? []);
    }

    public function test_log_is_append_only_and_cannot_be_mutated(): void
    {
        Role::factory()->create();
        $log = ActivityLog::query()->latest('id')->first();

        $this->expectException(RuntimeException::class);
        $log->update(['action' => 'tampered']);
    }
}
