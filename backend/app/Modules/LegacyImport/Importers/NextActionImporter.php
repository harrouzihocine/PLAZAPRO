<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Importers;

use App\Modules\LegacyImport\Support\BaseImporter;
use Illuminate\Database\Query\Builder;

/**
 * §5.11 — legacy tasks category 1 (call) / 2 (visit) ARE the materialized
 * next-action pipeline → next_actions on the client_project morph. The visit
 * type follows legacy_visit_type so it stays consistent with §5.10. source_*
 * stays NULL: legacy reports point at the task they fulfil, not the one they
 * spawn.
 */
class NextActionImporter extends BaseImporter
{
    public function phase(): string
    {
        return 'next_actions';
    }

    protected function sourceTable(): string
    {
        return 'tasks';
    }

    protected function targetTable(): string
    {
        return 'next_actions';
    }

    protected function sourceQuery(): Builder
    {
        return $this->ctx->legacy->table('tasks')->whereIn('category_id', [1, 2]);
    }

    protected function beforeRun(): void
    {
        $this->ctx->preloadMap('projects');
        $this->ctx->preloadMap('users');
    }

    protected function map(object $row): ?array
    {
        $dueAt = $this->t->ts($row->due_date) ?? $this->t->legacyTs($row->created_at);
        if ($dueAt === null) {
            $this->ctx->warn('next_action_no_date', "Legacy task #{$row->id} (cat {$row->category_id}) has no usable due date — skipped.");

            return null;
        }

        $visitType = $this->ctx->cfg('legacy_visit_type') === 'in_site' ? 'in_site_visit' : 'office_visit';

        return [
            'subject_type' => 'client_project',
            'subject_id' => $this->ctx->requireMapId('projects', $row->project_id),
            'type' => (int) $row->category_id === 1 ? 'call' : $visitType,
            'due_at' => $dueAt,
            'assigned_to' => $this->ctx->mapId('users', $row->assigned_to),
            'state' => $row->is_complete ? 'done' : 'pending',
            'completed_at' => $this->t->legacyTs($row->completed_at),
            'status' => 'active',
            'created_at' => $this->t->legacyTs($row->created_at) ?? $dueAt,
            'updated_at' => $this->t->legacyTs($row->updated_at) ?? $dueAt,
        ];
    }
}
