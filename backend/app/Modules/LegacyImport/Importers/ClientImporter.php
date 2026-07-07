<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Importers;

use App\Modules\LegacyImport\Support\BaseImporter;

/**
 * §5.2 — the 14,771 legacy clients. Duplicate phones import as-is (no unique
 * index; the app's client_duplicate_requests workflow resolves them later —
 * the reporter emits duplicate-clients.csv). phone_nsn is a stored generated
 * column, derived by MySQL from phone. Displaced facts (phone2, wilaya,
 * lossy name splits) land in notes.
 */
class ClientImporter extends BaseImporter
{
    /** @var array<int, string> legacy wilaya id → name */
    private array $wilayaNames = [];

    public function phase(): string
    {
        return 'clients';
    }

    protected function sourceTable(): string
    {
        return 'clients';
    }

    protected function targetTable(): string
    {
        return 'clients';
    }

    protected function beforeRun(): void
    {
        $this->wilayaNames = $this->ctx->legacy->table('wilayas')->pluck('name', 'id')->all();
        $this->ctx->preloadMap('sources');
        $this->ctx->preloadMap('ratings');
        $this->ctx->preloadMap('users');
    }

    protected function map(object $row): ?array
    {
        $split = $this->t->splitName($row->name);

        $phone = $this->t->phone($row->phone1);
        if (! $phone['ok']) {
            $this->ctx->warn('phone_parse_failed', "Client #{$row->id} '{$row->name}': unparseable phone '{$row->phone1}' kept verbatim.");
        } elseif ($phone['foreign']) {
            $this->ctx->warn('phone_foreign', "Client #{$row->id} '{$row->name}': non-DZ phone kept as {$phone['phone']}.");
        }

        $phone2 = trim((string) $row->phone2) !== '' ? $this->t->phone($row->phone2) : null;

        return [
            'first_name' => $split['first'],
            'last_name' => $split['last'],
            'phone' => $phone['phone'] ?? '',
            'source_id' => $this->ctx->mapId('sources', $row->source_id),
            'rating_id' => $this->ctx->mapId('ratings', $row->rating_id),
            'assigned_agent_id' => $this->ctx->mapId('users', $row->assigned_to),
            'created_by' => $this->ctx->mapId('users', $row->assigned_to),
            'notes' => $this->t->noteBlock($row->id, [
                'Tél 2' => $phone2 !== null ? ($phone2['phone'] ?? $row->phone2) : null,
                'Wilaya' => $row->wilaya_id !== null ? ($this->wilayaNames[$row->wilaya_id] ?? null) : null,
                'Nom (legacy)' => $split['lossy'] ? trim((string) $row->name) : null,
            ]),
            'status' => 'active',
            'created_at' => $this->t->legacyTs($row->created_at),
            'updated_at' => $this->t->legacyTs($row->updated_at),
        ];
    }
}
