<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Models;

use App\Core\Models\BaseModel;
use App\Modules\Collaboration\Enums\MessageType;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A single chat message. `subject` optionally references a shared record
 * (client/unit/deal) — a "share to chat". A "deleted" message is redacted via
 * cancel() (BaseModel), never physically removed.
 */
class Message extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'conversation_id', 'user_id', 'type', 'body', 'subject_type', 'subject_id', 'edited_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => MessageType::class,
            'edited_at' => 'datetime',
        ]);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
