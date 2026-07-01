<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Core\Models\BaseModel;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded instalment payment — the guide's canonical no-delete row. Never
 * edited or deleted: corrections go through supersedeWith (HasVersions), which
 * cancels this row and inserts a linked replacement. Money is decimal(12,2).
 */
class Versement extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'client_project_id', 'amount', 'paid_on', 'method_id', 'reference',
        'schedule_item_id', 'recorded_by', 'document_id',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'amount' => 'decimal:2',
            'paid_on' => 'date',
        ]);
    }

    public function clientProject(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(DynamicListItem::class, 'method_id');
    }

    public function scheduleItem(): BelongsTo
    {
        return $this->belongsTo(PaymentSchedule::class, 'schedule_item_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
