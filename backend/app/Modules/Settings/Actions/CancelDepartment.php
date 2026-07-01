<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Core\Enums\RecordStatus;
use App\Modules\Settings\Models\Department;

/**
 * Cancel (no-delete) a department. Guarded: a department that still has active
 * users can't be cancelled — reassign or deactivate them first, so nobody is
 * left pointing at a cancelled department.
 */
class CancelDepartment
{
    public function handle(Department $department, string $reason): Department
    {
        abort_if(
            $department->users()->where('status', RecordStatus::Active->value)->exists(),
            422,
            "Reassign or deactivate this department's users before cancelling it.",
        );

        return $department->cancel($reason);
    }
}
