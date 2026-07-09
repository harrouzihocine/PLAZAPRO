<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Models;

use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An editable sales target for a period + dimension, backing the KPI command
 * center's "target attainment" figures. Not a domain record — plain, mutable
 * configuration (no cancel-and-duplicate lifecycle), like the app_settings row.
 */
class SalesTarget extends Model
{
    protected $fillable = [
        'scope', 'scope_id', 'scope_key', 'period_type', 'period_start',
        'metric', 'target_amount', 'created_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'target_amount' => 'decimal:2',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
