<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Importers;

use App\Modules\LegacyImport\Support\BaseImporter;
use Illuminate\Database\Query\Builder;

/**
 * §5.13 — booked_estate WITHOUT a booking (units presented at a visit, 710
 * rows) → shortlist_items, attributed to the imported visit. state starts
 * 'shortlisted'; the §6 derivation pass upgrades it when the same
 * (project, unit) closed in a deal. Booking-linked rows are handled by the
 * bookings phase (reservations + synth shortlist).
 */
class ShortlistImporter extends BaseImporter
{
    /** @var array<int, int> legacy visit id → legacy project id */
    private array $visitProjects = [];

    public function phase(): string
    {
        return 'shortlist';
    }

    protected function sourceTable(): string
    {
        return 'booked_estate';
    }

    protected function targetTable(): string
    {
        return 'shortlist_items';
    }

    protected function sourceQuery(): Builder
    {
        return $this->ctx->legacy->table('booked_estate')->whereNull('booking_report_id');
    }

    protected function beforeRun(): void
    {
        $this->visitProjects = $this->ctx->legacy->table('visit_reports')
            ->pluck('project_id', 'id')->map(fn ($id) => (int) $id)->all();
        $this->ctx->preloadMap('visit_reports');
        $this->ctx->preloadMap('projects');
        $this->ctx->preloadMap('estates');
    }

    protected function map(object $row): ?array
    {
        if ($row->box_local_id !== null) {
            $this->ctx->warn('shortlist_box_local', "booked_estate #{$row->id} carries a box_local — not covered by the plan's data profile; unit part imported, box skipped.");
        }

        // 83 legacy rows reference visits deleted from the old CRM (no FK on
        // visit_report_id): without the visit there is no project to attach
        // the shortlist item to — ledger + skip.
        if (! isset($this->visitProjects[(int) $row->visit_report_id])) {
            $this->ctx->warn('shortlist_visit_deleted', "booked_estate #{$row->id} (estate {$row->estate_id}, ".substr((string) $row->created_at, 0, 10).") references deleted visit #{$row->visit_report_id} — unattributable, skipped.");

            return null;
        }

        return [
            'client_project_id' => $this->ctx->requireMapId('projects', $this->visitProjects[(int) $row->visit_report_id]),
            'office_visit_id' => $this->ctx->requireMapId('visit_reports', $row->visit_report_id),
            'shortlistable_type' => 'unit',
            'shortlistable_id' => $this->ctx->requireMapId('estates', $row->estate_id),
            'state' => 'shortlisted',
            'note' => null,
            'status' => 'active',
            'created_at' => $this->t->legacyTs($row->created_at),
            'updated_at' => $this->t->legacyTs($row->updated_at),
        ];
    }

    protected function insertOnlyColumns(): array
    {
        // state is owned by the app + derivation pass after insert (§5.13/§6).
        return ['status', 'state'];
    }
}
