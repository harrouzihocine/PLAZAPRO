<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Models\BaseModel;
use App\Modules\Inventory\Enums\MediaType;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A polymorphic, versioned media asset stored on a private disk. Files are never
 * deleted: replacing bumps `version` and cancels the old row.
 */
class Media extends BaseModel
{
    use HasFactory;

    protected $table = 'media';

    protected $fillable = [
        'collection', 'type', 'disk', 'path', 'original_name', 'mime_type',
        'size_bytes', 'cdn_url', 'preview_path', 'preview_status', 'sort_order',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => MediaType::class,
            'size_bytes' => 'integer',
            'version' => 'integer',
            'sort_order' => 'integer',
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
