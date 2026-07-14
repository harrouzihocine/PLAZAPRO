<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Models;

use App\Modules\Settings\Models\Role;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A human-authored broadcast: a permitted user's custom message, sent as a
 * notification to selected users / a role / everyone. A transient UX record like
 * the notifications table (not audited BaseModel) — the message itself is
 * delivered per-recipient through the normal DomainNotification pipeline; this
 * row backs the sender-side history and its read tracking.
 *
 * @property array<string, string> $body_translations
 */
class Broadcast extends Model
{
    protected $fillable = [
        'sender_id',
        'audience_type',
        'role_id',
        'body_translations',
        'recipient_count',
    ];

    protected function casts(): array
    {
        return [
            'body_translations' => 'array',
            'recipient_count' => 'integer',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** Snapshot of exactly who this broadcast was sent to. */
    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'broadcast_recipients')->withTimestamps();
    }

    /** The message in a specific language, falling back to any filled one. */
    public function bodyFor(?string $locale): string
    {
        $t = $this->body_translations ?? [];

        return $t[$locale] ?? $t['en'] ?? $t['fr'] ?? $t['ar'] ?? (string) reset($t);
    }
}
