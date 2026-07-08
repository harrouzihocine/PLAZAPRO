<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Resources;

use App\Modules\Clients\Models\Deal;
use App\Modules\Clients\Models\DealItem;
use App\Modules\Payments\Models\Versement;
use App\Modules\Settings\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin Deal
 */
class DealResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Resolve every credited agent name for this deal in ONE query.
        $creditNames = $this->creditNames();

        return [
            'id' => $this->id,
            'client_project_id' => $this->client_project_id,
            'visit_id' => $this->visit_id,
            'call_id' => $this->call_id,
            'state' => $this->state?->value,
            'total_price' => $this->total_price,
            'notes' => $this->notes,
            'status' => $this->status?->value,
            'created_at' => $this->created_at,
            // Split so the UI renders apartments/locals and their boxes apart. The
            // full property card (not just the code) rides along on each item; each
            // apartment carries its OWN lifecycle (open → won/lost + agreed
            // price) and its boxes point back via parent_item_id.
            'units' => $this->whenLoaded('items', fn () => $this->items
                ->filter(fn (DealItem $i) => $i->unit_id !== null && $i->isActive())
                ->values()
                ->map(fn (DealItem $i) => [
                    'item_id' => $i->id,
                    'state' => $i->state?->value,
                    'agreed_price' => $i->agreed_price,
                    'closed_at' => $i->closed_at,
                    'id' => $i->unit->id,
                    'reference' => $i->unit->reference,
                    'type' => $i->unit->location?->type?->localizedLabel(),
                    'floor' => $i->unit->floor?->localizedLabel(),
                    'area_sqm' => $i->unit->area_sqm,
                    'price' => $i->unit->price,
                    'sale_status' => $i->unit->sale_status?->value,
                    // Reserved deposit context: when the client paid a holding
                    // deposit on this apartment it shows Reserved with the money
                    // collected so far and, if held, the deadline.
                    'reserved_expires_at' => $i->unit->reserved_expires_at,
                    'collected' => (string) Versement::query()->active()
                        ->where('client_project_id', $this->client_project_id)
                        ->where('unit_id', $i->unit_id)
                        ->whereNull('refunded_at')
                        ->sum('amount'),
                    // Who was credited for the sale (won apartments).
                    'credited' => $this->creditedFor($i, $creditNames),
                    'location' => $i->unit->location?->name,
                    'location_id' => $i->unit->location_id,
                ])),
            'boxes' => $this->whenLoaded('items', fn () => $this->items
                ->filter(fn (DealItem $i) => $i->box_id !== null && $i->isActive())
                ->values()
                ->map(fn (DealItem $i) => [
                    'item_id' => $i->id,
                    'parent_item_id' => $i->parent_item_id,
                    'state' => $i->state?->value,
                    'box_linked' => $i->box_linked,
                    'id' => $i->box->id,
                    'reference' => $i->box->reference,
                    'type' => $i->box->type?->localizedLabel(),
                    'price' => $i->box->price,
                    'sale_status' => $i->box->sale_status?->value,
                    'location_id' => $i->box->location_id,
                ])),
        ];
    }

    /** id → name for every user credited across this deal's items (one query). */
    private function creditNames(): Collection
    {
        if (! $this->resource->relationLoaded('items')) {
            return collect();
        }

        $ids = $this->items->pluck('credits')->filter()
            ->flatMap(fn ($c) => array_merge($c['sale'] ?? [], $c['insite'] ?? [], $c['other'] ?? []))
            ->map(fn ($id) => (int) $id)->unique();

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()->whereIn('id', $ids)->pluck('name', 'id');
    }

    /**
     * @return array{sale: list<string>, insite: list<string>, other: list<string>}|null
     */
    private function creditedFor(DealItem $item, Collection $names): ?array
    {
        $credits = $item->credits;
        if (! is_array($credits) || $credits === []) {
            return null;
        }

        $resolve = fn (string $key) => collect($credits[$key] ?? [])
            ->map(fn ($id) => $names[(int) $id] ?? null)
            ->filter()->values()->all();

        return ['sale' => $resolve('sale'), 'insite' => $resolve('insite'), 'other' => $resolve('other')];
    }
}
