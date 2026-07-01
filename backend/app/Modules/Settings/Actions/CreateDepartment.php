<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Models\Department;
use Illuminate\Support\Str;

class CreateDepartment
{
    public function handle(array $data): Department
    {
        return Department::create([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? $this->uniqueSlug($data['name']),
        ]);
    }

    /** Derive a URL-safe, unique slug from the name. */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'department';
        $slug = $base;
        $n = 2;

        while (Department::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
