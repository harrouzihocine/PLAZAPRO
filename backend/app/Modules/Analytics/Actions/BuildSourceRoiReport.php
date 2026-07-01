<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Models\Visit;
use App\Modules\Settings\Models\DynamicListItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Source ROI: a cohort report that follows leads acquired per source through to
 * visits, won deals, and revenue. Pure read over clients / visits /
 * client_projects — reproducible from the base tables, no summary table.
 *
 * "Cohort" = the leads (clients) created inside the optional date window; visits
 * and won deals are those belonging to that same cohort, so a won sale is always
 * attributed to the source that originally brought the client in.
 *
 * source_id is COALESCEd to 0 in SQL so an unattributed bucket keys cleanly as an
 * int (PHP arrays can't key on null).
 */
class BuildSourceRoiReport
{
    /**
     * @return list<array<string, mixed>>
     */
    public function handle(?string $from = null, ?string $to = null): array
    {
        $range = function (Builder $q) use ($from, $to): Builder {
            return $q
                ->when($from, fn ($qq) => $qq->whereDate('clients.created_at', '>=', $from))
                ->when($to, fn ($qq) => $qq->whereDate('clients.created_at', '<=', $to));
        };

        $leads = Client::query()->active()
            ->tap($range)
            ->selectRaw('COALESCE(source_id, 0) as source_id, COUNT(*) as leads')
            ->groupBy('source_id')
            ->pluck('leads', 'source_id');

        $visits = Visit::query()->active()
            ->join('clients', 'clients.id', '=', 'visits.client_id')
            ->where('clients.status', 'active')
            ->tap($range)
            ->selectRaw('COALESCE(clients.source_id, 0) as source_id, COUNT(*) as visits')
            ->groupBy('source_id')
            ->pluck('visits', 'source_id');

        $won = ClientProject::query()->active()
            ->where('client_projects.stage', 'won')
            ->join('clients', 'clients.id', '=', 'client_projects.client_id')
            ->where('clients.status', 'active')
            ->tap($range)
            ->selectRaw('COALESCE(clients.source_id, 0) as source_id, COUNT(*) as won, COALESCE(SUM(client_projects.total_price), 0) as revenue')
            ->groupBy('source_id')
            ->get()
            ->keyBy('source_id');

        $sourceIds = $leads->keys()
            ->merge($visits->keys())
            ->merge($won->keys())
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $labels = DynamicListItem::whereIn('id', $sourceIds->reject(fn ($id) => $id === 0))
            ->pluck('label', 'id');

        return $sourceIds
            ->map(function (int $sid) use ($leads, $visits, $won, $labels): array {
                $leadCount = (int) $leads->get($sid, 0);
                $wonRow = $won->get($sid);
                $wonCount = (int) ($wonRow->won ?? 0);

                return [
                    'source_id' => $sid === 0 ? null : $sid,
                    'source' => $sid === 0 ? 'Unattributed' : ($labels->get($sid) ?? 'Unknown'),
                    'leads' => $leadCount,
                    'visits' => (int) $visits->get($sid, 0),
                    'won' => $wonCount,
                    'revenue' => number_format((float) ($wonRow->revenue ?? 0), 2, '.', ''),
                    'conversion' => $leadCount > 0 ? round($wonCount / $leadCount * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('won')
            ->values()
            ->all();
    }
}
