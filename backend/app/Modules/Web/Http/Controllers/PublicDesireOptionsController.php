<?php

declare(strict_types=1);

namespace App\Modules\Web\Http\Controllers;

use App\Modules\Settings\Models\Commune;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\Wilaya;
use App\Modules\Web\Support\FormToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * The option lists behind the public "what are you looking for" (desire) form.
 * Deliberately company-agnostic data only: the official wilaya/commune
 * geography and the generic project-type / room-count vocabularies — never
 * inventory, prices or anything an unpublished project could leak through.
 * Items carry their full label_translations so a language switch re-renders
 * without a refetch (the showcase convention).
 */
class PublicDesireOptionsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [
            'types' => $this->listItems('project_types'),
            'room_numbers' => $this->listItems('room_numbers'),
            'wilayas' => Wilaya::query()->active()
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Wilaya $w) => ['id' => $w->id, 'code' => $w->code, 'name' => $w->name]),
            'communes' => Commune::query()->active()
                ->orderBy('name')
                ->get(['id', 'wilaya_id', 'name'])
                ->map(fn (Commune $c) => ['id' => $c->id, 'wilaya_id' => $c->wilaya_id, 'name' => $c->name]),
            'form_token' => FormToken::issue(),
        ]]);
    }

    /** One dynamic list's active options: id + base label + {en,fr,ar} labels. */
    private function listItems(string $key): array
    {
        return DynamicListItem::query()->active()
            ->whereHas('list', fn ($q) => $q->where('key', $key))
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get(['id', 'label', 'label_translations'])
            ->map(fn (DynamicListItem $item) => [
                'id' => $item->id,
                'label' => $item->label,
                'labels' => $item->label_translations ?? (object) [],
            ])
            ->all();
    }
}
