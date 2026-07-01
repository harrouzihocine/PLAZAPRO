<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Models\Department;
use Illuminate\Support\Arr;

class UpdateDepartment
{
    public function handle(Department $department, array $data): Department
    {
        $department->update(Arr::only($data, ['name', 'slug']));

        return $department->fresh();
    }
}
