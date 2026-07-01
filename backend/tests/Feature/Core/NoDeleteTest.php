<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Exceptions\RecordDeletionException;
use App\Modules\Settings\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_hard_delete_is_refused(): void
    {
        $role = Role::factory()->create();

        $this->expectException(RecordDeletionException::class);
        $role->delete();
    }

    public function test_force_delete_is_refused(): void
    {
        $role = Role::factory()->create();

        $this->expectException(RecordDeletionException::class);
        $role->forceDelete();
    }

    public function test_cancel_sets_status_and_keeps_the_row(): void
    {
        $role = Role::factory()->create();

        $role->cancel('duplicate entry');

        $this->assertTrue($role->fresh()->isCancelled());
        $this->assertSame('duplicate entry', $role->fresh()->cancellation_reason);
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'status' => 'cancelled']);
    }

    public function test_active_scope_excludes_cancelled(): void
    {
        $active = Role::factory()->create();
        $cancelled = Role::factory()->create();
        $cancelled->cancel('gone');

        $ids = Role::query()->active()->pluck('id');

        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($cancelled->id));
    }
}
