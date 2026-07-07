<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Importers;

use App\Modules\LegacyImport\Support\BaseImporter;
use Illuminate\Support\Str;

/**
 * §5.1 — the 19 legacy accounts. Matched by email before insert (protects
 * against overlap with existing PLAZA accounts); bcrypt hashes carried
 * verbatim so everyone keeps their password. Roles/permissions are NEVER
 * imported — legacy roles map to new slugs, junk roles fall back + warn.
 */
class UserImporter extends BaseImporter
{
    /** @var array<int, string> legacy role id → name */
    private array $legacyRoles = [];

    /** @var array<int, string> legacy department id → name */
    private array $legacyDepartments = [];

    /** @var array<string, int> new role slug → id */
    private array $roleIds = [];

    /** @var array<string, int> new department slug → id */
    private array $departmentIds = [];

    public function phase(): string
    {
        return 'users';
    }

    protected function sourceTable(): string
    {
        return 'users';
    }

    protected function targetTable(): string
    {
        return 'users';
    }

    protected function beforeRun(): void
    {
        $this->legacyRoles = $this->ctx->legacy->table('roles')->pluck('name', 'id')->all();
        $this->legacyDepartments = $this->ctx->legacy->table('departments')->pluck('name', 'id')->all();
        $this->roleIds = $this->ctx->target->table('roles')->pluck('id', 'slug')->map(fn ($id) => (int) $id)->all();
        $this->departmentIds = $this->ctx->target->table('departments')->pluck('id', 'slug')->map(fn ($id) => (int) $id)->all();
    }

    protected function map(object $row): ?array
    {
        $roleName = $this->legacyRoles[$row->role_id] ?? null;
        $roleSlug = $this->ctx->cfg("role_map.{$roleName}");
        if ($roleSlug === null) {
            $roleSlug = $this->ctx->cfg('role_fallback');
            $this->ctx->warn('user_junk_role', "User '{$row->name}' <{$row->email}> has legacy role '{$roleName}' — mapped to {$roleSlug}.");
        }

        $deptName = $this->legacyDepartments[$row->department_id] ?? null;
        $deptSlug = $deptName !== null ? $this->ctx->cfg("department_map.{$deptName}") : null;

        return [
            'name' => $row->name,
            'email' => $row->email,
            'username' => $this->username($row),
            'password' => $row->password,
            'phone' => $this->t->phone($row->phone)['phone'],
            'is_active' => (int) ($row->is_active && ! $row->leave),
            'role_id' => $this->roleIds[$roleSlug],
            'department_id' => $deptSlug !== null ? ($this->departmentIds[$deptSlug] ?? null) : null,
            'status' => 'active',
            'created_at' => $this->t->legacyTs($row->created_at),
            'updated_at' => $this->t->legacyTs($row->updated_at),
        ];
    }

    protected function matchExisting(object $row, array $payload): ?int
    {
        $existing = $this->ctx->target->table('users')->where('email', $row->email)->first(['id']);
        if ($existing !== null) {
            $this->ctx->warn('user_email_overlap', "Legacy user <{$row->email}> matched an existing PLAZA account — adopted, not overwritten.");

            return (int) $existing->id;
        }

        return null;
    }

    /**
     * Email local-part, lowercased, unique-suffixed (§5.1). Stable across
     * re-runs: a username already owned by this row's own target is kept.
     */
    private function username(object $row): string
    {
        $base = Str::lower(Str::before($row->email, '@'));
        $base = preg_replace('/[^a-z0-9._-]/', '', $base) ?: 'user';
        $mine = $this->ctx->mapId('users', $row->id);

        $candidate = $base;
        $i = 2;
        while (true) {
            $owner = $this->ctx->target->table('users')->where('username', $candidate)->value('id');
            if ($owner === null || ($mine !== null && (int) $owner === $mine)) {
                return $candidate;
            }
            $candidate = $base.$i++;
        }
    }
}
