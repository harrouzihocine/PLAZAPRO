<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Enums\ShortlistState;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Inventory\Enums\FinishType;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Support\Collection;

/**
 * Add properties to a deal's shortlist — ADDITIVE with dedupe, unlike SyncShortlist
 * (full replace at the office visit). Used by call logging, where the spec allows
 * "multiple interest additions" across calls: re-sending a property already on the
 * active shortlist is a no-op, never a duplicate. `call_id` records provenance.
 */
class AddShortlistItems
{
    /**
     * @param  list<array{shortlistable_type: string, shortlistable_id: int|string, note?: string|null, finish_type?: string|null}>  $items
     */
    public function handle(ClientProject $project, array $items, ?int $callId = null): Collection
    {
        foreach ($items as $item) {
            $type = $item['shortlistable_type'];
            $id = (int) $item['shortlistable_id'];

            abort_unless(ShortlistItem::morphTargetExists($type, $id), 422, 'A selected property does not exist.');

            $already = ShortlistItem::query()->active()
                ->where('client_project_id', $project->id)
                ->where('shortlistable_type', $type)
                ->where('shortlistable_id', $id)
                ->exists();

            if ($already) {
                continue;
            }

            ShortlistItem::create([
                'client_project_id' => $project->id,
                'call_id' => $callId,
                'shortlistable_type' => $type,
                'shortlistable_id' => $id,
                'state' => ShortlistState::Shortlisted->value,
                'note' => $item['note'] ?? null,
                'finish_type' => $this->finishFor($type, $id, $item['finish_type'] ?? null),
            ]);
        }

        return $project->shortlistItems()->active()->withProperty()->get();
    }

    /**
     * The finish proposed on a fresh row: boxes carry none; a unit takes the
     * explicit choice (guarded against finishes it doesn't offer), else its
     * default (semi-fini when offered).
     */
    private function finishFor(string $type, int $id, ?string $explicit): ?string
    {
        if ($type !== 'unit') {
            return null;
        }

        $unit = Unit::query()->findOrFail($id);

        if ($explicit !== null) {
            $finish = FinishType::from($explicit);
            abort_if(
                $unit->priceFor($finish) === null,
                422,
                "Unit {$unit->reference} has no {$finish->value} price.",
            );

            return $finish->value;
        }

        return $unit->defaultFinish()->value;
    }
}
