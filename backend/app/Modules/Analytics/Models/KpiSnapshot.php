<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A daily materialized KPI value written by the `kpi:snapshot` command. One row
 * per (snapshot_date, metric, dimension); dimension is null for the company-wide
 * figure or a "kind:id" key otherwise. Read back as time-series for the trend
 * charts. Not a domain record — machine-written, idempotent per day.
 */
class KpiSnapshot extends Model
{
    protected $fillable = ['snapshot_date', 'metric', 'dimension', 'value', 'meta'];

    protected $casts = [
        'snapshot_date' => 'date',
        'value' => 'decimal:2',
        'meta' => 'array',
    ];
}
