<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Models;

use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user's emoji reaction to a message. Deliberately a plain model (not
 * BaseModel): a reaction is toggled state like the read cursor, not an audited
 * domain row — removing one is a hard delete, and there is no version chain.
 */
class MessageReaction extends Model
{
    /** The fixed picker set — the API accepts nothing outside it. */
    public const EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

    protected $fillable = ['message_id', 'user_id', 'emoji'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
