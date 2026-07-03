<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Enums\ShortlistState;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Clients\Models\ShortlistItem;
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
     * @param  list<array{shortlistable_type: string, shortlistable_id: int|string, note?: string|null}>  $items
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

                if ($existing) {
                    $existing->update(['note' => $item['note'] ?? $existing->note]);
                    $keep[] = $existing->id;
                } else {
                    $keep[] = ShortlistItem::create([
                        'client_project_id' => $project->id,
                        'office_visit_id' => $officeVisitId,
                        'shortlistable_type' => $type,
                        'shortlistable_id' => $id,
                        'state' => ShortlistState::Shortlisted->value,
                        'note' => $item['note'] ?? null,
                    ])->id;
                }
            }

            ShortlistItem::query()->active()
                ->where('client_project_id', $project->id)
                ->whereNotIn('id', $keep)
                ->get()->each->cancel('Removed from shortlist');

            return $project->shortlistItems()->active()->with('shortlistable')->get();
        });
    }
}
