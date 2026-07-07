<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Importers;

use App\Modules\LegacyImport\Support\BaseImporter;
use App\Modules\LegacyImport\Support\DynamicListResolver;

/**
 * §5.5 — legacy estate_categories (residences) → locations. Converges onto
 * hand-entered rows (PERLA, EL MOURDJAN) by normalized name / config pin
 * instead of duplicating them; adopted rows only get NULL columns filled.
 * Delivery method / boxes / locals / plan path land in description (no new
 * home). Payment methods go through the location_payment_methods pivot.
 */
class LocationImporter extends BaseImporter
{
    /** @var array<int, string> */
    private array $locationLabels = [];

    /** @var array<int, string> */
    private array $residenceTypes = [];

    /** @var array<int, string> */
    private array $contractTypes = [];

    /** @var array<int, string> */
    private array $paymentMethods = [];

    /** @var array<int, string> */
    private array $deliveryMethods = [];

    /** @var array<string, array{id: int, wilaya_id: ?int}> commune label (config) → target commune */
    private array $communes = [];

    public function phase(): string
    {
        return 'locations';
    }

    protected function sourceTable(): string
    {
        return 'estate_categories';
    }

    protected function targetTable(): string
    {
        return 'locations';
    }

    protected function beforeRun(): void
    {
        $legacy = $this->ctx->legacy;
        $this->locationLabels = $legacy->table('estate_locations')->pluck('location', 'id')->all();
        $this->residenceTypes = $legacy->table('estate_residence_types')->pluck('type', 'id')->all();
        $this->contractTypes = $legacy->table('estate_contract_types')->pluck('type', 'id')->all();
        $this->paymentMethods = $legacy->table('estate_payment_methods')->pluck('method', 'id')->all();
        $this->deliveryMethods = $legacy->table('estate_delivery_methods')->pluck('method', 'id')->all();

        $names = array_filter(array_unique(array_values((array) $this->ctx->cfg('commune_map', []))));
        foreach ($this->ctx->target->table('communes')->whereIn('name', $names)->get(['id', 'name', 'wilaya_id']) as $commune) {
            $this->communes[$commune->name] = ['id' => (int) $commune->id, 'wilaya_id' => $commune->wilaya_id !== null ? (int) $commune->wilaya_id : null];
        }
    }

    protected function map(object $row): ?array
    {
        $communeLabel = $this->locationLabels[$row->location] ?? null;
        $commune = $this->commune($communeLabel);

        $descriptionParts = array_filter([
            trim((string) $row->description),
            $this->t->noteBlock($row->id, [
                'Livraison' => $this->deliveryMethods[$row->delivery_method] ?? null,
                'Boxes' => $row->has_box ? 'oui' : null,
                'Locaux' => $row->has_local ? 'oui' : null,
                'Plan (legacy)' => $row->map,
            ]),
        ]);

        return [
            'name' => trim((string) $row->name),
            'code' => $this->code($row),
            'wilaya_id' => $commune['wilaya_id'] ?? null,
            'commune_id' => $commune['id'] ?? null,
            'type_id' => $this->lists->resolve('project_types', $this->residenceTypes[$row->residence_type] ?? null),
            'contract_type_id' => $this->lists->resolve('contract_types', $this->contractTypes[$row->contract_type] ?? null),
            'address' => $communeLabel,
            'description' => $descriptionParts === [] ? null : implode("\n\n", $descriptionParts),
            'gtm_priority' => 'medium',
            'status' => 'active',
            'created_at' => $this->t->legacyTs($row->created_at),
            'updated_at' => $this->t->legacyTs($row->updated_at),
        ];
    }

    protected function insertOnlyColumns(): array
    {
        return ['status', 'code', 'gtm_priority'];
    }

    protected function matchExisting(object $row, array $payload): ?int
    {
        $name = trim((string) $row->name);
        $pinned = $this->ctx->cfg('location_name_pins', [])[$name] ?? null;
        $needle = DynamicListResolver::normalize($pinned ?? $name);

        foreach ($this->ctx->target->table('locations')->get(['id', 'name']) as $location) {
            if (DynamicListResolver::normalize($location->name) === $needle) {
                return (int) $location->id;
            }
        }

        return null;
    }

    protected function afterSync(object $row, int $targetId, string $mode, array $payload): void
    {
        $itemId = $this->lists->resolve('project_payment_methods', $this->paymentMethods[$row->payment_method] ?? null);
        if ($itemId === null || $this->ctx->dryRun || $targetId < 0) {
            return;
        }
        $exists = $this->ctx->target->table('location_payment_methods')
            ->where('location_id', $targetId)
            ->where('dynamic_list_item_id', $itemId)
            ->exists();
        if (! $exists) {
            $now = now()->format('Y-m-d H:i:s');
            $this->ctx->insert('location_payment_methods', [
                'location_id' => $targetId,
                'dynamic_list_item_id' => $itemId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->ctx->count($this->phase(), 'pivots');
        }
    }

    /** @return array{id: int, wilaya_id: ?int}|null */
    private function commune(?string $label): ?array
    {
        if ($label === null || trim($label) === '') {
            return null;
        }
        $map = (array) $this->ctx->cfg('commune_map', []);
        if (! array_key_exists(trim($label), $map)) {
            $this->ctx->warn('commune_unmapped', "Legacy estate_location '{$label}' is not in the commune map.");

            return null;
        }
        $name = $map[trim($label)];

        return $name !== null ? ($this->communes[$name] ?? null) : null;
    }

    /**
     * Uppercase ASCII slug of the name, unique-suffixed against
     * locations.code (UNIQUE). Stable: a code owned by this row's own target
     * is kept. Adopted rows keep their existing code (code is insert-only and
     * fill-null never fires on a NOT NULL column).
     */
    private function code(object $row): string
    {
        $base = strtoupper($this->t->slug(trim((string) $row->name), '-'));
        $mine = $this->ctx->mapId('estate_categories', $row->id);

        $candidate = $base;
        $i = 2;
        while (true) {
            $owner = $this->ctx->target->table('locations')->where('code', $candidate)->value('id');
            if ($owner === null || ($mine !== null && (int) $owner === $mine)) {
                return $candidate;
            }
            $candidate = $base.'-'.$i++;
        }
    }
}
