<?php

declare(strict_types=1);

namespace App\Modules\Clients\Models;

use App\Core\Models\BaseModel;
use App\Modules\Clients\Enums\ShortlistState;
use App\Modules\Inventory\Models\Box;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Pipeline\Models\Call;
use App\Modules\Pipeline\Models\Visit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A property (unit or box) the client wants, shortlisted during a call or at the
 * office visit (call_id / office_visit_id record the provenance). The row persists
 * across phases; `state` tracks its journey (shortlisted → in-site outcome →
 * won/lost). Cancellable, so removing one from the shortlist keeps history.
 */
class ShortlistItem extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'client_project_id', 'office_visit_id', 'call_id',
        'shortlistable_type', 'shortlistable_id', 'state', 'note',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'state' => ShortlistState::class,
        ]);
    }

    public function clientProject(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class);
    }

    public function officeVisit(): BelongsTo
    {
        return $this->belongsTo(Visit::class, 'office_visit_id');
    }

    /** The call during which the property was shortlisted (Phase-2 qualification). */
    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    /** The shortlisted property — a unit (apartment / local) or a box. */
    public function shortlistable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Guard a morph target actually exists (morph FKs are not DB-enforced). */
    public static function morphTargetExists(string $type, int $id): bool
    {
        return match ($type) {
            'unit' => Unit::query()->whereKey($id)->exists(),
            'box' => Box::query()->whereKey($id)->exists(),
            default => false,
        };
    }
}
