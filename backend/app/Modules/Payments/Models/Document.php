<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Core\Models\BaseModel;
use App\Modules\Payments\Enums\DocumentType;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A branded, generated PDF (receipt/contract/quote/schedule) owned polymorphically
 * by any record. Stored on a private disk; `number` is unique/sequential; `meta`
 * snapshots the rendered figures so a reprint stays faithful. Never hard-deleted.
 */
class Document extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'documentable_type', 'documentable_id', 'type', 'number', 'template',
        'disk', 'path', 'render_status', 'version', 'generated_by', 'generated_at', 'meta',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => DocumentType::class,
            'version' => 'integer',
            'generated_at' => 'datetime',
            'meta' => 'array',
        ]);
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function isReady(): bool
    {
        return $this->render_status === 'ready' && $this->path !== null;
    }
}
