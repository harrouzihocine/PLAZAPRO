<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Clients\Models\ClientProject;
use App\Modules\Settings\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The read side behind the Archive desk: the company-wide list of archived
 * (lost / closed) client projects, so a manager can review them in one place,
 * filter, and reactivate the recoverable ones. Parked-as-desire projects
 * (closed_to_desire_at set) are deliberately excluded — those live on the client
 * file / Matches board, not here.
 *
 * Company-wide by design, like the other oversight monitors (no scopeVisibleTo);
 * the endpoint is gated by oversight.archive. Never mutates.
 *
 * @phpstan-type Filters array{search?: ?string, reason?: ?string, location_id?: int|string|null, agent_id?: int|string|null, from?: ?string, to?: ?string, min_price?: int|string|null, max_price?: int|string|null, sort?: ?string, per_page?: int|null}
 */
class BuildArchive
{
    /** The em-dash separator ArchiveClientProject joins "label — note" with. */
    private const REASON_SEPARATOR = ' — ';

    /**
     * The archived-project listing (paginated) plus a summary strip over the
     * whole filtered set.
     *
     * @return array{items: LengthAwarePaginator, summary: array{total: int, total_value: string, by_reason: list<array{label: string, count: int}>, by_agent: list<array{user_id: int|null, name: string, count: int}>}}
     */
    public function handle(array $f = []): array
    {
        $base = $this->baseQuery($f);

        // Clamp the page size: a sane default, never unbounded.
        $perPage = max(1, min(100, (int) ($f['per_page'] ?? 25)));
        $oldest = ($f['sort'] ?? 'recent') === 'oldest';

        $items = (clone $base)
            ->with(['client:id,first_name,last_name', 'location:id,name', 'unit:id,reference', 'creator:id,name'])
            // Archived date = updated_at (Cancellable has no archived_at column; the
            // archive() call is the row's last write, so updated_at is the stamp).
            ->orderBy('updated_at', $oldest ? 'asc' : 'desc')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (ClientProject $p) => [
                'id' => $p->id,
                'client_id' => $p->client_id,
                'client' => $p->client?->full_name,
                'unit' => $p->unit?->reference,
                'location' => $p->location?->name,
                'price' => $p->total_price,
                'reason' => $p->cancellation_reason,
                'opened_by' => $p->creator?->name,
                'archived_at' => $p->updated_at,
                'age_days' => $p->updated_at !== null ? (int) $p->updated_at->diffInDays(now()) : null,
            ]);

        return [
            'items' => $items,
            'summary' => [
                'total' => (clone $base)->count(),
                'total_value' => number_format((float) (clone $base)->sum('total_price'), 2, '.', ''),
                'by_reason' => $this->byReason(clone $base),
                'by_agent' => $this->byUser(clone $base, 'created_by'),
            ],
        ];
    }

    /** Archived, lost/closed (not parked-desire) projects with all filters applied. */
    public function baseQuery(array $f): Builder
    {
        return ClientProject::query()
            ->archived()
            ->whereNull('closed_to_desire_at')
            ->when(! empty($f['search']), fn (Builder $q) => $this->applySearch($q, (string) $f['search']))
            ->when(! empty($f['reason']), fn (Builder $q) => $this->applyReason($q, (string) $f['reason']))
            ->when(! empty($f['location_id']), fn (Builder $q) => $q->where('location_id', (int) $f['location_id']))
            ->when(! empty($f['agent_id']), fn (Builder $q) => $q->where('created_by', (int) $f['agent_id']))
            ->when(! empty($f['from']), fn (Builder $q) => $q->whereDate('updated_at', '>=', $f['from']))
            ->when(! empty($f['to']), fn (Builder $q) => $q->whereDate('updated_at', '<=', $f['to']))
            ->when(isset($f['min_price']) && $f['min_price'] !== '' && $f['min_price'] !== null, fn (Builder $q) => $q->where('total_price', '>=', $f['min_price']))
            ->when(isset($f['max_price']) && $f['max_price'] !== '' && $f['max_price'] !== null, fn (Builder $q) => $q->where('total_price', '<=', $f['max_price']));
    }

    /**
     * Search on the client behind the project — name or phone. Mirrors
     * ClientController@index: phone is matched digits-only (trunk 0 stripped) so a
     * fragment matches regardless of how the number is written.
     */
    private function applySearch(Builder $q, string $term): void
    {
        $term = trim($term);
        $digits = ltrim(preg_replace('/\D/', '', $term), '0');

        $q->whereHas('client', function (Builder $c) use ($term, $digits) {
            $c->where(function (Builder $sub) use ($term, $digits) {
                $sub->where('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%");
                if ($digits !== '') {
                    $sub->orWhereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') LIKE ?", ["%{$digits}%"]);
                } else {
                    $sub->orWhere('phone', 'like', "%{$term}%");
                }
            });
        });
    }

    /**
     * Filter by archive reason. The reason is stored free-text as "label" or
     * "label — note", so we match the label exactly or as the prefix of a noted
     * reason.
     */
    private function applyReason(Builder $q, string $label): void
    {
        $q->where(function (Builder $w) use ($label) {
            $w->where('cancellation_reason', $label)
                ->orWhere('cancellation_reason', 'like', $label.self::REASON_SEPARATOR.'%');
        });
    }

    /**
     * Count per archive-reason label (the part before the " — note").
     *
     * @return list<array{label: string, count: int}>
     */
    private function byReason(Builder $query): array
    {
        $expr = "SUBSTRING_INDEX(cancellation_reason, '".self::REASON_SEPARATOR."', 1)";

        return $query
            ->select(DB::raw("{$expr} as label"), DB::raw('COUNT(*) as total'))
            ->groupByRaw($expr)
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['label' => (string) ($r->label ?: '—'), 'count' => (int) $r->total])
            ->all();
    }

    /**
     * Per-user counts for a column holding a user id (who opened the project).
     *
     * @return list<array{user_id: int|null, name: string, count: int}>
     */
    private function byUser(Builder $query, string $column): array
    {
        $counts = $query->select($column, DB::raw('COUNT(*) as total'))
            ->groupBy($column)->pluck('total', $column);

        $names = User::query()->whereIn('id', $counts->keys()->filter())->pluck('name', 'id');

        return $counts->map(fn ($total, $id) => [
            'user_id' => $id !== null ? (int) $id : null,
            'name' => $id ? ($names[$id] ?? '—') : 'Unknown',
            'count' => (int) $total,
        ])->values()->all();
    }
}
