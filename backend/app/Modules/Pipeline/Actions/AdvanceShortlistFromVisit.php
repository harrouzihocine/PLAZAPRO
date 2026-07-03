<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Actions;

use App\Modules\Clients\Enums\ShortlistState;
use App\Modules\Clients\Models\ShortlistItem;
use App\Modules\Pipeline\Enums\VisitType;
use App\Modules\Pipeline\Models\Visit;

/**
 * After a site agent completes an in-site visit, move the matching shortlisted
 * property's state from its recorded outcome (insite_outcomes). This is what drives
 * Phase 6: only "interested" properties become closable deals.
 */
class AdvanceShortlistFromVisit
{
    public function handle(Visit $visit): void
    {
        if ($visit->type !== VisitType::InSite || ! $visit->client_project_id || ! $visit->unit_id) {
            return;
        }

        $item = ShortlistItem::query()->active()
            ->where('client_project_id', $visit->client_project_id)
            ->where('shortlistable_type', 'unit')
            ->where('shortlistable_id', $visit->unit_id)
            ->first();

        $state = match ($visit->outcome?->value) {
            'visited_interested' => ShortlistState::VisitedInterested,
            'visited_not_interested' => ShortlistState::VisitedNotInterested,
            'not_visited', 'needs_second_visit' => ShortlistState::NotVisited,
            default => null,
        };

        if ($item && $state) {
            $item->update(['state' => $state->value]);
        }
    }
}
