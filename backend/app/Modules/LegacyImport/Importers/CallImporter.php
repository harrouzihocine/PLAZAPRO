<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Importers;

use App\Modules\LegacyImport\Support\BaseImporter;

/**
 * §5.10 — the 47,298 call_reports → calls. All outbound (call-team CRM);
 * Darija descriptions verbatim; no outcome/topics/objections (legacy never
 * structured them).
 */
class CallImporter extends BaseImporter
{
    /** @var array<int, int> legacy project id → legacy client id */
    private array $projectClients = [];

    public function phase(): string
    {
        return 'calls';
    }

    protected function sourceTable(): string
    {
        return 'call_reports';
    }

    protected function targetTable(): string
    {
        return 'calls';
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
        $calledAt = $this->t->ts($row->call_date) ?? $this->t->legacyTs($row->created_at);
        if ($calledAt === null) {
            $this->ctx->warn('call_no_date', "Legacy call_report #{$row->id} has no usable date — skipped.");

            return null;
        }

        return [
            'client_id' => $this->ctx->requireMapId('clients', $this->projectClients[(int) $row->project_id]),
            'client_project_id' => $this->ctx->requireMapId('projects', $row->project_id),
            'agent_id' => $this->ctx->requireMapId('users', $row->created_by),
            'direction' => 'outbound',
            'notes' => $row->description,
            'called_at' => $calledAt,
            'status' => 'active',
            'created_at' => $this->t->legacyTs($row->created_at) ?? $calledAt,
            'updated_at' => $this->t->legacyTs($row->updated_at) ?? $calledAt,
        ];
    }
}
