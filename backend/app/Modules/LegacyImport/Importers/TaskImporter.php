<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Importers;

use App\Modules\LegacyImport\Support\BaseImporter;
use Illuminate\Database\Query\Builder;

/**
 * §5.11 — legacy tasks of the booking/extending/payment categories (3/5/6)
 * → generic tasks on the client_project morph (there are no such
 * next_action types). assigned_by has no column — skipped by design.
 */
class TaskImporter extends BaseImporter
{
    /** @var array<int, object{name: string, arabe_name: ?string}> */
    private array $categories = [];

    public function phase(): string
    {
        return 'tasks';
    }

    protected function sourceTable(): string
    {
        return 'tasks';
    }

    protected function targetTable(): string
    {
        return 'tasks';
    }

    protected function sourceQuery(): Builder
    {
        return $this->ctx->legacy->table('tasks')->whereNotIn('category_id', [1, 2]);
    }

    protected function beforeRun(): void
    {
        $this->categories = $this->ctx->legacy->table('categories')->get()->keyBy('id')->all();
        $this->ctx->preloadMap('projects');
        $this->ctx->preloadMap('users');
    }

    protected function map(object $row): ?array
    {
        if (! in_array((int) $row->category_id, [3, 5, 6], true)) {
            $this->ctx->warn('task_unexpected_category', "Legacy task #{$row->id} has unexpected category {$row->category_id} — imported as generic task.");
        }
        $category = $this->categories[$row->category_id] ?? null;
        $title = 'Legacy: '.($category->name ?? "category {$row->category_id}")
            .(($category->arabe_name ?? null) !== null ? ' / '.$category->arabe_name : '');

        $assignedTo = $this->ctx->mapId('users', $row->assigned_to);
        if ($assignedTo === null) {
            $assignedTo = $this->ctx->requireSystemUserId();
            $this->ctx->warn('task_orphan_assignee', "Legacy task #{$row->id} has no mappable assignee — assigned to the system user.");
        }

        return [
            'title' => $title,
            'description' => null,
            'assigned_to' => $assignedTo,
            'subject_type' => 'client_project',
            'subject_id' => $this->ctx->requireMapId('projects', $row->project_id),
            'due_at' => $this->t->ts($row->due_date),
            'priority' => 'normal',
            'state' => $row->is_complete ? 'done' : 'open',
            'status' => 'active',
            'created_at' => $this->t->legacyTs($row->created_at) ?? $this->t->ts($row->due_date),
            'updated_at' => $this->t->legacyTs($row->updated_at) ?? $this->t->ts($row->due_date),
        ];
    }
}
