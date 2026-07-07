<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Importers;

use App\Modules\LegacyImport\Support\BaseImporter;

/**
 * Legacy project_member (user ↔ project access grants; 265 rows) →
 * client_project_viewers. NOT in the original plan — discovered populated
 * during staging; decision (Hocine, 2026-07-06): import as project viewers,
 * skipping the rows where the member is the project's own creator (owners
 * are implicit viewers in PLAZA).
 */
class ProjectViewerImporter extends BaseImporter
{
    /** @var array<int, int> legacy project id → legacy creator user id */
    private array $projectCreators = [];

    public function phase(): string
    {
        return 'project_viewers';
    }

    protected function sourceTable(): string
    {
        return 'project_member';
    }

    protected function targetTable(): string
    {
        return 'client_project_viewers';
    }

    protected function beforeRun(): void
    {
        $this->projectCreators = $this->ctx->legacy->table('projects')
            ->pluck('created_by', 'id')->map(fn ($id) => (int) $id)->all();
    }

    protected function map(object $row): ?array
    {
        if (($this->projectCreators[(int) $row->project_id] ?? null) === (int) $row->user_id) {
            return null; // creator self-grant — owners are implicit viewers
        }

        return [
            'client_project_id' => $this->ctx->requireMapId('projects', $row->project_id),
            'user_id' => $this->ctx->requireMapId('users', $row->user_id),
            'added_by' => null,
            'hidden_at' => null,
            'created_at' => $this->t->legacyTs($row->created_at),
            'updated_at' => $this->t->legacyTs($row->updated_at),
        ];
    }

    protected function insertOnlyColumns(): array
    {
        return [];
    }

    protected function matchExisting(object $row, array $payload): ?int
    {
        // Unique (client_project_id, user_id) — adopt an existing grant.
        $id = $this->ctx->target->table('client_project_viewers')
            ->where('client_project_id', $payload['client_project_id'])
            ->where('user_id', $payload['user_id'])
            ->value('id');

        return $id !== null ? (int) $id : null;
    }
}
