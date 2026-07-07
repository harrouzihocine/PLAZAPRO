<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Importers;

use App\Modules\LegacyImport\Support\BaseImporter;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * §4.1 — find-or-create the inactive "Legacy Import" system user, used only
 * where a target column is NOT NULL and legacy has no author
 * (media.uploaded_by, orphan task assignees).
 */
class SystemUserImporter extends BaseImporter
{
    public function phase(): string
    {
        return 'system_user';
    }

    protected function sourceTable(): string
    {
        return 'synth:system_user';
    }

    protected function targetTable(): string
    {
        return 'users';
    }

    protected function map(object $row): ?array
    {
        return null; // unused — custom run()
    }

    public function run(): void
    {
        $cfg = $this->ctx->cfg('system_user');

        $existing = $this->ctx->target->table('users')->where('email', $cfg['email'])->first(['id']);
        if ($existing !== null) {
            $this->ctx->systemUserId = (int) $existing->id;
            if ($this->ctx->getMap($this->sourceTable(), 1) === null) {
                $this->ctx->putMap($this->sourceTable(), 1, 'users', $this->ctx->systemUserId, md5($cfg['email']), adopted: true);
            }
            $this->ctx->count($this->phase(), 'skipped');

            return;
        }

        $role = $this->ctx->target->table('roles')->where('slug', $cfg['role_slug'])->first(['id']);
        $now = now()->format('Y-m-d H:i:s');
        $id = $this->ctx->insert('users', [
            'name' => $cfg['name'],
            'email' => $cfg['email'],
            'username' => $cfg['username'],
            'role_id' => $role?->id,
            'is_active' => 0,
            'password' => Hash::make(Str::random(40)),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->ctx->putMap($this->sourceTable(), 1, 'users', $id, md5($cfg['email']));
        $this->ctx->systemUserId = $id;
        $this->ctx->count($this->phase(), 'inserted');
    }
}
