<?php

declare(strict_types=1);

namespace App\Modules\Web\Services;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Web\Models\WebLead;
use App\Modules\Web\Models\WebStatEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Aggregates web_stat_events into the website-stats board payload: headline
 * numbers (with Δ vs the previous window of the same length), a per-day
 * traffic series, and the most-viewed projects/units. "Visits" = unique
 * anonymous session keys that viewed at least one page in the window.
 */
class BuildWebsiteStats
{
    public function build(int $days): array
    {
        $to = CarbonImmutable::now();
        $from = $to->startOfDay()->subDays($days - 1);
        $prevFrom = $from->subDays($days);
        $prevTo = $from->subSecond();

        $counts = $this->eventCounts($from, $to);
        $prevCounts = $this->eventCounts($prevFrom, $prevTo);

        $visits = $this->uniqueVisitors($from, $to);
        $prevVisits = $this->uniqueVisitors($prevFrom, $prevTo);

        $leads = $this->leadCount($from, $to);
        $prevLeads = $this->leadCount($prevFrom, $prevTo);

        return [
            'days' => $days,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => [
                'visits' => $visits,
                'page_views' => (int) ($counts['page_view'] ?? 0),
                'project_views' => (int) ($counts['project_view'] ?? 0),
                'unit_views' => (int) ($counts['unit_view'] ?? 0),
                'leads' => $leads,
                'whatsapp_clicks' => (int) ($counts['whatsapp_click'] ?? 0),
                'phone_clicks' => (int) ($counts['phone_click'] ?? 0),
                'social_clicks' => (int) ($counts['social_click'] ?? 0),
                'shares' => (int) ($counts['share_click'] ?? 0),
            ],
            'deltas' => [
                'visits' => $this->delta($visits, $prevVisits),
                'page_views' => $this->delta((int) ($counts['page_view'] ?? 0), (int) ($prevCounts['page_view'] ?? 0)),
                'leads' => $this->delta($leads, $prevLeads),
            ],
            'series' => $this->series($from, $to),
            'top_projects' => $this->topProjects($from, $to),
            'top_units' => $this->topUnits($from, $to),
            'devices' => $this->devices($from, $to),
            'locales' => $this->locales($from, $to),
        ];
    }

    private function eventCounts(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return WebStatEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('event, COUNT(*) AS total')
            ->groupBy('event')
            ->pluck('total', 'event');
    }

    private function uniqueVisitors(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) WebStatEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('event', 'page_view')
            ->distinct()
            ->count('session_key');
    }

    /** Real submissions from the inbox (spam triaged out), not the tracker. */
    private function leadCount(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) WebLead::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('lead_status', '!=', 'spam')
            ->count();
    }

    /** Δ% vs the previous window; null when there is no baseline at all. */
    private function delta(int $current, int $previous): ?int
    {
        if ($previous === 0) {
            return null;
        }

        return (int) round(($current - $previous) / $previous * 100);
    }

    /** Per-day visitors + page views, zero-filled so charts have no holes. */
    private function series(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = WebStatEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('event', 'page_view')
            ->selectRaw('DATE(created_at) AS day, COUNT(DISTINCT session_key) AS visitors, COUNT(*) AS views')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $series = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $key = $day->toDateString();
            $series[] = [
                'date' => $key,
                'visits' => (int) ($rows[$key]->visitors ?? 0),
                'page_views' => (int) ($rows[$key]->views ?? 0),
            ];
        }

        return $series;
    }

    private function topProjects(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $views = WebStatEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('event', 'project_view')
            ->whereNotNull('location_id')
            ->selectRaw('location_id, COUNT(*) AS views, COUNT(DISTINCT session_key) AS visitors')
            ->groupBy('location_id')
            ->orderByDesc('views')
            ->limit(10)
            ->get();

        if ($views->isEmpty()) {
            return [];
        }

        $names = Location::query()->whereIn('id', $views->pluck('location_id'))->pluck('name', 'id');

        $leads = WebLead::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('lead_status', '!=', 'spam')
            ->whereIn('location_id', $views->pluck('location_id'))
            ->selectRaw('location_id, COUNT(*) AS total')
            ->groupBy('location_id')
            ->pluck('total', 'location_id');

        return $views
            // Junk ids from the open internet never joined to a project — drop them.
            ->filter(fn ($row) => $names->has($row->location_id))
            ->map(fn ($row) => [
                'id' => (int) $row->location_id,
                'name' => $names[$row->location_id],
                'views' => (int) $row->views,
                'visitors' => (int) $row->visitors,
                'leads' => (int) ($leads[$row->location_id] ?? 0),
            ])->values()->all();
    }

    private function topUnits(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $views = WebStatEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('event', 'unit_view')
            ->whereNotNull('unit_id')
            ->selectRaw('unit_id, COUNT(*) AS views')
            ->groupBy('unit_id')
            ->orderByDesc('views')
            ->limit(10)
            ->get();

        if ($views->isEmpty()) {
            return [];
        }

        $units = Unit::query()->with('location:id,name')
            ->whereIn('id', $views->pluck('unit_id'))
            ->get(['id', 'reference', 'location_id'])
            ->keyBy('id');

        return $views
            ->filter(fn ($row) => $units->has($row->unit_id))
            ->map(fn ($row) => [
                'id' => (int) $row->unit_id,
                'reference' => $units[$row->unit_id]->reference,
                'project' => $units[$row->unit_id]->location?->name,
                'project_id' => $units[$row->unit_id]->location_id,
                'views' => (int) $row->views,
            ])->values()->all();
    }

    private function devices(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = WebStatEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('event', 'page_view')
            ->selectRaw('is_mobile, COUNT(DISTINCT session_key) AS visitors')
            ->groupBy('is_mobile')
            ->pluck('visitors', 'is_mobile');

        return [
            'mobile' => (int) ($rows[1] ?? 0),
            'desktop' => (int) ($rows[0] ?? 0),
        ];
    }

    private function locales(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return WebStatEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('event', 'page_view')
            ->whereNotNull('locale')
            ->selectRaw('locale, COUNT(DISTINCT session_key) AS visitors')
            ->groupBy('locale')
            ->orderByDesc('visitors')
            ->pluck('visitors', 'locale')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
