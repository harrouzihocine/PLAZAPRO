<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Importers;

use App\Modules\LegacyImport\Support\BaseImporter;

/**
 * §5.10 — the 1,052 visit_reports → visits, typed per
 * config('legacy_import.legacy_visit_type') (default office: units were
 * presented at these visits, and it lets shortlist_items.office_visit_id
 * attribution work). has_booked=1 (657 rows, soft "interested" flag) →
 * visit_outcomes:interested; has_visited is dead (100% zero).
 */
class VisitImporter extends BaseImporter
{
    /** @var array<int, int> legacy project id → legacy client id */
    private array $projectClients = [];

    public function phase(): string
    {
        return 'visits';
    }

    protected function sourceTable(): string
    {
        return 'visit_reports';
    }

    protected function targetTable(): string
    {
        return 'visits';
    }

    protected function beforeRun(): void
    {
        $this->projectClients = $this->ctx->legacy->table('projects')
            ->pluck('client_id', 'id')->map(fn ($id) => (int) $id)->all();
        $this->ctx->preloadMap('projects');
        $this->ctx->preloadMap('clients');
        $this->ctx->preloadMap('users');
    }

    protected function map(object $row): ?array
    {
        $visitedAt = $this->t->ts($row->visit_date) ?? $this->t->legacyTs($row->created_at);
        if ($visitedAt === null) {
            $this->ctx->warn('visit_no_date', "Legacy visit_report #{$row->id} has no usable date — skipped.");

            return null;
        }

        return [
            'client_id' => $this->ctx->requireMapId('clients', $this->projectClients[(int) $row->project_id]),
            'client_project_id' => $this->ctx->requireMapId('projects', $row->project_id),
            'type' => (string) $this->ctx->cfg('legacy_visit_type'),
            'agent_id' => $this->ctx->requireMapId('users', $row->created_by),
            'scheduled_at' => $visitedAt,
            'completed_at' => $visitedAt,
            'outcome_id' => $row->has_booked ? $this->lists->byValue('visit_outcomes', 'interested') : null,
            'notes' => $row->description,
            'status' => 'active',
            'created_at' => $this->t->legacyTs($row->created_at) ?? $visitedAt,
            'updated_at' => $this->t->legacyTs($row->updated_at) ?? $visitedAt,
        ];
    }
}
