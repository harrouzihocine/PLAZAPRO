<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Enums\ShortlistState;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Inventory\Enums\FinishType;
use App\Modules\Inventory\Models\Unit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Replace a deal's active property shortlist with the given set (add / keep /
 * remove). Existing rows keep their journey `state` (a visited property is not
 * reset to "shortlisted"); properties dropped from the list are cancelled, not
 * deleted, so history stays. The FormRequest enforces "at least one".
 */
class SyncShortlist
{
    /**
     * @param  list<array{shortlistable_type: string, shortlistable_id: int|string, note?: string|null, finish_type?: string|null}>  $items
     */
    public function handle(ClientProject $project, array $items, ?int $officeVisitId = null): Collection
    {
        return DB::transaction(function () use ($project, $items, $officeVisitId) {
            $keep = [];

            foreach ($items as $item) {
                $type = $item['shortlistable_type'];
                $id = (int) $item['shortlistable_id'];

                // Guard the morph target exists (morph FKs are not DB-enforced).
                abort_unless(ShortlistItem::morphTargetExists($type, $id), 422, 'A shortlisted property does not exist.');

                $existing = ShortlistItem::query()->active()
                    ->where('client_project_id', $project->id)
                    ->where('shortlistable_type', $type)
                    ->where('shortlistable_id', $id)
                    ->first();

                // The finish PROPOSED to the client — units only. An explicit
                // choice must be one the unit actually offers; omitted keeps the
                // existing proposal (or defaults on a fresh row).
                $finish = $this->resolveFinish($type, $id, $item['finish_type'] ?? null, $existing);

                if ($existing) {
                    $existing->update([
                        'note' => $item['note'] ?? $existing->note,
                        'finish_type' => $finish,
                    ]);
                    $keep[] = $existing->id;
                } else {
                    $keep[] = ShortlistItem::create([
                        'client_project_id' => $project->id,
                        'office_visit_id' => $officeVisitId,
                        'shortlistable_type' => $type,
                        'shortlistable_id' => $id,
                        'state' => ShortlistState::Shortlisted->value,
                        'note' => $item['note'] ?? null,
                        'finish_type' => $finish,
                    ])->id;
                }
            }

            $toDrop = ShortlistItem::query()->active()
                ->where('client_project_id', $project->id)
                ->whereNotIn('id', $keep)
                ->get();

            foreach ($toDrop as $item) {
                $reason = $item->lockedReason();
                abort_if(
                    $reason !== null,
                    422,
                    "This property is {$reason} on an open deal and can't be removed from the shortlist until it's released.",
                );
            }

            $toDrop->each->cancel('Removed from shortlist');

            return $project->shortlistItems()->active()->withProperty()->get();
        });
    }

    /**
     * The finish to store on a shortlist row: boxes carry none; a unit takes the
     * explicit choice (guarded against finishes the unit doesn't offer), else
     * keeps the current proposal, else defaults (semi-fini when offered).
     */
    private function resolveFinish(string $type, int $id, ?string $explicit, ?ShortlistItem $existing): ?string
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

        $current = $existing?->finish_type;
        if ($current !== null && $unit->priceFor($current) !== null) {
            return $current->value;
        }

        return $unit->defaultFinish()->value;
    }
}
