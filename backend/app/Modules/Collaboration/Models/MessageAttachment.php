<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Models;

use App\Core\Models\BaseModel;
use App\Modules\Collaboration\Enums\AttachmentKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An image, voice note or file on a message. Stored on the private `chat` disk
 * with a UUID name; served only through the participant-gated streaming endpoint.
 */
class MessageAttachment extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'kind', 'disk', 'path', 'mime_type', 'size_bytes',
        'duration_ms', 'width', 'height', 'meta',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'kind' => AttachmentKind::class,
            'size_bytes' => 'integer',
            'duration_ms' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'meta' => 'array',
        ]);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
