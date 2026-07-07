<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Importers;

use App\Modules\LegacyImport\Support\BaseImporter;
use App\Modules\LegacyImport\Support\DynamicListResolver;

/**
 * §5.9 — legacy desires → desires, attached to the client's most recent
 * active project (else most recent, else none). client_interest (client ×
 * room-type, 10,671 rows / 9,066 clients) collapses to ONE synthetic desire
 * per client via synth:desire_interests — never one desire per interest.
 * Free-text wished location resolves to a commune when possible; the raw
 * label always survives in notes.
 */
class DesireImporter extends BaseImporter
{
    /** @var array<int, string> */
    private array $residenceTypes = [];

    /** @var array<int, string> */
    private array $rooms = [];

    /** @var array<int, string> */
    private array $floors = [];

    /** @var array<int, string> */
    private array $contractTypes = [];

    /** @var array<int, string> */
    private array $paymentMethods = [];

    /** @var array<int, string> */
    private array $deliveryMethods = [];

    /** @var array<int, int> legacy client id → best legacy project id (§5.9 attachment) */
    private array $bestProject = [];

    /** @var array<string, array{id: int, wilaya_id: ?int}> normalized commune name → target commune */
    private array $communesByName = [];

    public function phase(): string
    {
        return 'desires';
    }

    protected function sourceTable(): string
    {
        return 'desires';
    }

    protected function targetTable(): string
    {
        return 'desires';
    }

    protected function beforeRun(): void
    {
        $legacy = $this->ctx->legacy;
        $this->residenceTypes = $legacy->table('estate_residence_types')->pluck('type', 'id')->all();
        $this->rooms = $legacy->table('estate_rooms_numbers')->pluck('number', 'id')->all();
        $this->floors = $legacy->table('estate_floors')->pluck('floor', 'id')->all();
        $this->contractTypes = $legacy->table('estate_contract_types')->pluck('type', 'id')->all();
        $this->paymentMethods = $legacy->table('estate_payment_methods')->pluck('method', 'id')->all();
        $this->deliveryMethods = $legacy->table('estate_delivery_methods')->pluck('method', 'id')->all();

        // Most recent ACTIVE legacy project per client, else most recent (§5.9).
        $projects = $legacy->table('projects')
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'client_id', 'status']);
        $bestActive = [];
        $bestAny = [];
        foreach ($projects as $project) {
            $bestAny[(int) $project->client_id] = (int) $project->id;
            if ($project->status !== 'archive') {
                $bestActive[(int) $project->client_id] = (int) $project->id;
            }
        }
        $this->bestProject = $bestActive + $bestAny;

        foreach ($this->ctx->target->table('communes')->get(['id', 'name', 'wilaya_id']) as $commune) {
            $this->communesByName[DynamicListResolver::normalize($commune->name)] = [
                'id' => (int) $commune->id,
                'wilaya_id' => $commune->wilaya_id !== null ? (int) $commune->wilaya_id : null,
            ];
        }

        $this->ctx->preloadMap('clients');
        $this->ctx->preloadMap('projects');
    }

    protected function map(object $row): ?array
    {
        $floorLabel = $row->floor !== null ? ($this->floors[$row->floor] ?? null) : null;
        $floorId = $this->lists->resolve('floors', $floorLabel);
        $commune = $this->commune($row->location);

        // A handful of legacy desires carry a raw-DZD typo instead of
        // millions-de-centimes: ×10000 overflows decimal(12,2). Keep the raw
        // value in notes, leave budget_max NULL, ledger it.
        $budget = $this->t->priceDa($row->price);
        $rawBudgetNote = null;
        if ($budget !== null && $budget > 9999999999.99) {
            $rawBudgetNote = (string) $row->price;
            $budget = null;
            $this->ctx->warn('desire_budget_overflow', "Desire #{$row->id}: legacy price {$row->price} ×multiplier overflows budget_max — kept raw in notes.");
        }

        return [
            'client_id' => $this->ctx->requireMapId('clients', $row->client_id),
            'client_project_id' => $this->projectFor((int) $row->client_id),
            'wilaya_id' => $commune['wilaya_id'] ?? null,
            'commune_id' => $commune['id'] ?? null,
            'type_id' => $this->lists->resolve('project_types', $row->residence_type !== null ? ($this->residenceTypes[$row->residence_type] ?? null) : null),
            'room_number_id' => $this->lists->resolve('room_numbers', $row->rooms_number !== null ? ($this->rooms[$row->rooms_number] ?? null) : null),
            'contract_type_id' => $this->lists->resolve('contract_types', $row->contract_type !== null ? ($this->contractTypes[$row->contract_type] ?? null) : null),
            'floor_id' => $floorId,
            'floor_pref' => $floorId === null && $floorLabel !== null ? $floorLabel : null,
            'area_min' => $row->area,
            'budget_max' => $budget,
            'notes' => $this->t->noteBlock($row->id, [
                'Lieu souhaité' => $row->location,
                'Livraison' => $row->delivery_method !== null ? ($this->deliveryMethods[$row->delivery_method] ?? null) : null,
                'Paiement' => $row->payment_method !== null ? ($this->paymentMethods[$row->payment_method] ?? null) : null,
                'Budget (legacy, brut)' => $rawBudgetNote,
            ]),
            'status' => 'active',
            'created_at' => $this->t->legacyTs($row->created_at),
            'updated_at' => $this->t->legacyTs($row->updated_at),
        ];
    }

    protected function afterRun(): void
    {
        // client_interest aggregate → one synthetic desire per client (§5.9).
        $interests = $this->ctx->legacy->table('client_interest')
            ->orderBy('client_id')->orderBy('id')
            ->get(['client_id', 'estate_rooms_number_id', 'created_at', 'updated_at'])
            ->groupBy('client_id');

        foreach ($interests->chunk($this->ctx->chunkSize) as $chunk) {
            $this->ctx->target->transaction(function () use ($chunk): void {
                foreach ($chunk as $clientId => $rows) {
                    $labels = $rows->pluck('estate_rooms_number_id')
                        ->map(fn ($id) => $this->rooms[$id] ?? "#{$id}")
                        ->unique()->values();

                    $payload = [
                        'client_id' => $this->ctx->requireMapId('clients', $clientId),
                        'client_project_id' => $this->projectFor((int) $clientId),
                        'room_number_id' => $labels->count() === 1
                            ? $this->lists->resolve('room_numbers', $labels->first())
                            : null,
                        'notes' => 'Intérêts (legacy): '.$labels->implode(', '),
                        'status' => 'active',
                        'created_at' => $this->t->legacyTs($rows->min('created_at')),
                        'updated_at' => $this->t->legacyTs($rows->max('updated_at')),
                    ];
                    $this->sync('synth:desire_interests', (int) $clientId, 'desires', $payload);
                }
            });
        }
    }

    private function projectFor(int $legacyClientId): ?int
    {
        return $this->ctx->mapId('projects', $this->bestProject[$legacyClientId] ?? null);
    }

    /** @return array{id: int, wilaya_id: ?int}|null */
    private function commune(?string $location): ?array
    {
        $location = trim((string) $location);
        if ($location === '') {
            return null;
        }
        $mapped = ((array) $this->ctx->cfg('commune_map', []))[$location] ?? null;
        if ($mapped !== null) {
            return $this->communesByName[DynamicListResolver::normalize($mapped)] ?? null;
        }

        return $this->communesByName[DynamicListResolver::normalize($location)] ?? null;
    }
}
