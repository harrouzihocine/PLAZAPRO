<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateRole
{
    public function handle(array $data): Role
    {
        return DB::transaction(function () use ($data) {
            $role = Role::create([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? $this->uniqueSlug($data['name']),
                'description' => $data['description'] ?? null,
                'is_agent' => $data['is_agent'] ?? false,
            ]);

            if (isset($data['permissions'])) {
                $role->permissions()->sync($data['permissions']);
                $role->logActivity('permissions', ['permissions' => array_values($data['permissions'])]);
            }

            return $role->load('permissions');
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'role';
        $slug = $base;
        $n = 2;

        while (Role::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
