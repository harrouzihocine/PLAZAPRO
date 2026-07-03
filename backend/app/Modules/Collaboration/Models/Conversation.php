<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Models;

use App\Core\Models\BaseModel;
use App\Modules\Collaboration\Enums\ConversationType;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A chat thread. Visibility is defined entirely by its participant rows: a user
 * only ever sees conversations they participate in (scopeVisibleTo). Direct
 * threads hold exactly two people; groups are named and can hold many.
 */
class Conversation extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'type', 'title', 'subject_type', 'subject_id', 'created_by', 'last_message_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => ConversationType::class,
            'last_message_at' => 'datetime',
        ]);
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_user')
            ->withPivot(['role', 'joined_at', 'last_read_at', 'muted']);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Newest message for the inbox preview. Uses latest('id') (not latestOfMany)
     * to avoid the ambiguous-column self-join seen with column-subset eager loads.
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latest('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** Only conversations the given user participates in. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('participants', fn ($q) => $q->where('users.id', $user->id));
    }

    public function hasParticipant(User $user): bool
    {
        return $this->participants()->where('users.id', $user->id)->exists();
    }

    /**
     * Read access: participants always; holders of chat.view_project_chats may
     * additionally READ any client-project chat (oversight) without being a
     * contributor. Writing stays participant-only everywhere — an overseer who
     * wants to talk joins the project as a contributor.
     */
    public function isReadableBy(User $user): bool
    {
        return $this->hasParticipant($user)
            || ($this->type === ConversationType::Project && $user->can('chat.view_project_chats'));
    }

    public function isAdmin(User $user): bool
    {
        return $this->participants()
            ->where('users.id', $user->id)
            ->wherePivot('role', 'admin')
            ->exists();
    }
}
