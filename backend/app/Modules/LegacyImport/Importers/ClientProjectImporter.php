<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Importers;

use App\Modules\LegacyImport\Support\BaseImporter;

/**
 * §5.8 — legacy projects (the pipeline cards — NOT residences!) →
 * client_projects. Stage/status per the stage_map; archived rows resolve
 * their pre-archive stage from last_status, downgraded to
 * archived_lead_stage (default lost) when that lands on lead. stage is
 * insert-only (§2.5: the app owns stage after import; §6 upgrades to won);
 * location/unit/total_price stay NULL — the derivation pass fills them from
 * transactions.
 */
class ClientProjectImporter extends BaseImporter
{
    public function phase(): string
    {
        return 'client_projects';
    }

    protected function sourceTable(): string
    {
        return 'projects';
    }

    protected function targetTable(): string
    {
        return 'client_projects';
    }

    protected function beforeRun(): void
    {
        $this->ctx->preloadMap('clients');
        $this->ctx->preloadMap('users');
    }

    protected function map(object $row): ?array
    {
        $archived = $row->status === 'archive';
        $stageMap = (array) $this->ctx->cfg('stage_map');

        if ($archived) {
            $stage = $stageMap[$row->last_status ?? 'expected'] ?? 'lead';
            if ($stage === 'lead') {
                $stage = (string) $this->ctx->cfg('archived_lead_stage');
            }
        } else {
            $stage = $stageMap[$row->status] ?? 'lead';
        }

        return [
            'client_id' => $this->ctx->requireMapId('clients', $row->client_id),
            'hidden_from_owner' => 0,
            'created_by' => $this->ctx->mapId('users', $row->created_by),
            'stage' => $stage,
            'closed_to_desire_at' => $row->status === 'desires fullfiled'
                ? ($this->t->legacyTs($row->updated_at) ?? $this->t->ts($row->start_date))
                : null,
            'status' => $archived ? 'archived' : 'active',
            'archive_reason_id' => $archived ? $this->lists->byValue('archive_reasons', 'other') : null,
            'created_at' => $this->t->legacyTs($row->created_at) ?? $this->t->ts($row->start_date),
            'updated_at' => $this->t->legacyTs($row->updated_at) ?? $this->t->ts($row->start_date),
        ];
    }

    protected function insertOnlyColumns(): array
    {
        // stage is app-owned after insert (§2.5); status stays mapped so a
        // legacy archive during parallel-run flows through.
        return ['stage'];
    }
}
