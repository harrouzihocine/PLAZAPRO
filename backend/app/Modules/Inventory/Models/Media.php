<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Models\BaseModel;
use App\Modules\Inventory\Enums\MediaCollection;
use App\Modules\Inventory\Enums\MediaType;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A polymorphic, versioned media asset stored on a private disk. Rows are never
 * deleted: replacing bumps `version` and cancels the old row (each version keeps
 * its own file). The one byte-level exception is OptimizeMedia, which swaps a
 * row's file for a smaller visually-lossless re-encode and discards the raw
 * upload (owner decision 2026-07-09) — `original_size_bytes` records what it was.
 */
class Media extends BaseModel
{
    use HasFactory;

    protected $table = 'media';

    protected $fillable = [
        'collection', 'type', 'disk', 'path', 'original_name', 'mime_type',
        'size_bytes', 'cdn_url', 'preview_path', 'preview_status', 'sort_order',
        // Optimization pipeline (OptimizeMedia): status + derivative + facts.
        'optimize_status', 'thumb_path', 'original_size_bytes',
        'width', 'height', 'duration_seconds',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'collection' => MediaCollection::class,
            'type' => MediaType::class,
            'size_bytes' => 'integer',
            'version' => 'integer',
            'sort_order' => 'integer',
            'original_size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_seconds' => 'integer',
        ]);
    }

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
