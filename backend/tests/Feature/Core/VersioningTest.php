<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Modules\Settings\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VersioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_supersede_cancels_original_and_links_replacement(): void
    {
        $original = Role::factory()->create(['name' => 'Original', 'slug' => 'original-role', 'is_agent' => false]);

        // A correction supplies the changed attributes (here the unique slug too).
        $replacement = $original->supersedeWith(
            ['name' => 'Corrected', 'slug' => 'corrected-role'],
            'typo in name',
        );

        // Original is cancelled but still present (no delete).
        $this->assertTrue($original->fresh()->isCancelled());
        $this->assertDatabaseHas('roles', ['id' => $original->id, 'status' => 'cancelled']);

        // Replacement is active and links back to the original.
        $this->assertSame('Corrected', $replacement->name);
        $this->assertTrue($replacement->isActive());
        $this->assertSame($original->id, $replacement->supersedes_id);
        $this->assertSame($original->id, $replacement->supersedes->id);
    }
}
