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
use Illuminate\Support\Str;

/**
 * A single chat message. `subject` optionally references a shared record
 * (client/unit/deal) — a "share to chat". A "deleted" message is redacted via
 * cancel() (BaseModel), never physically removed.
 */
class Message extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'conversation_id', 'user_id', 'type', 'body', 'subject_type', 'subject_id', 'reply_to_id', 'edited_at',
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

    /** The message this one quotes (WhatsApp-style reply). */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    /**
     * The message as a one-line label — inbox previews and reply excerpts
     * (mirrored by the frontend's features/collaboration/preview.js).
     */
    public function previewLabel(): string
    {
        if ($this->isCancelled()) {
            return 'Message deleted';
        }

        return match ($this->type->value) {
            'image' => '📷 Photo',
            'voice' => '🎤 Voice note',
            'file' => '📎 File',
            default => (string) ($this->body ?? ''),
        };
    }

    /**
     * Compact block describing the quoted message — shared by MessageResource
     * and the MessageSent broadcast so live-appended replies render identically.
     * A redacted target keeps its author but withholds the excerpt.
     *
     * @return array<string, mixed>|null
     */
    public function replyPreview(): ?array
    {
        $target = $this->replyTo;
        if ($target === null) {
            return null;
        }

        $redacted = $target->isCancelled();

        return [
            'id' => $target->id,
            'author_id' => $target->user_id,
            'author_name' => $target->author?->name,
            'type' => $target->type->value,
            'redacted' => $redacted,
            'excerpt' => $redacted ? null : Str::limit($target->previewLabel(), 80),
        ];
    }
}
