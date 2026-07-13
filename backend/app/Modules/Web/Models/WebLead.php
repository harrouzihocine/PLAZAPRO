<?php

declare(strict_types=1);

namespace App\Modules\Web\Models;

use App\Core\Models\BaseModel;
use App\Modules\Clients\Models\Client;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\Commune;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\User;
use App\Modules\Settings\Models\Wilaya;
use App\Modules\Web\Enums\WebLeadStatus;
use App\Modules\Web\Enums\WebLeadType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A visitor's submission from the public showcase — the staging row between
 * the anonymous internet and the CRM. Created by guests (no created_by;
 * the audit log records a null actor), owned by web.leads holders, and
 * linked to the real Client once converted.
 */
class WebLead extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'name', 'phone', 'message', 'criteria', 'type', 'location_id', 'unit_id',
        'preferred_date', 'preferred_time', 'locale',
        'source_url', 'user_agent', 'ip_hash',
        'lead_status', 'converted_client_id', 'handled_by', 'handled_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => WebLeadType::class,
            'lead_status' => WebLeadStatus::class,
            'criteria' => 'array',
            'preferred_date' => 'date',
            'handled_at' => 'datetime',
        ]);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function convertedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'converted_client_id');
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /**
     * The desire criteria with ids resolved to display labels (dynamic-list
     * labels in the viewer's locale). For the staff inbox — a handful of
     * desire leads per page, so per-lead lookups on tiny tables are fine.
     */
    public function resolvedCriteria(): ?array
    {
        $criteria = $this->criteria;

        if ($criteria === null) {
            return null;
        }

        $labels = fn (array $ids) => DynamicListItem::query()
            ->whereIn('id', $ids)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (DynamicListItem $item) => $item->localizedLabel())
            ->all();

        return array_filter([
            'wilayas' => filled($criteria['wilaya_ids'] ?? null)
                ? Wilaya::whereIn('id', $criteria['wilaya_ids'])->orderBy('code')->pluck('name')->all()
                : null,
            'communes' => filled($criteria['commune_ids'] ?? null)
                ? Commune::whereIn('id', $criteria['commune_ids'])->orderBy('name')->pluck('name')->all()
                : null,
            'types' => filled($criteria['type_ids'] ?? null) ? $labels($criteria['type_ids']) : null,
            'room_numbers' => filled($criteria['room_number_ids'] ?? null) ? $labels($criteria['room_number_ids']) : null,
            'budget_min' => $criteria['budget_min'] ?? null,
            'budget_max' => $criteria['budget_max'] ?? null,
        ], fn ($v) => $v !== null);
    }
}
