<?php

declare(strict_types=1);

namespace App\Modules\Web\Models;

use App\Modules\Clients\Models\Client;
use App\Modules\Inventory\Models\Media;
use App\Modules\Settings\Models\User;
use App\Modules\Web\Http\Controllers\PublicProjectController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * A tokened, expiring bundle of media an agent sent to one of their clients
 * (WhatsApp "send photos" flow). Plain Model like WebsiteSpace: a share is a
 * link, not a domain record — it expires instead of being cancelled, and the
 * row itself (user/client/created_at) is the audit trail.
 */
class MediaShare extends Model
{
    /** How long a sent link keeps working. */
    public const TTL_DAYS = 90;

    protected $fillable = ['token', 'user_id', 'client_id', 'title', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public static function generateToken(): string
    {
        return Str::random(48);
    }

    /** Not yet expired — the only liveness rule a share has. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * May the anonymous holder of this share's token see this media file?
     * Mirrors PublicMediaGate's media-level rules (active + public collection)
     * but membership in the share replaces the published-project requirement —
     * the agent chose these exact items on purpose.
     */
    public function allows(?Media $media): bool
    {
        return $media !== null
            && $media->isActive()
            && in_array($media->collection, PublicProjectController::PUBLIC_COLLECTIONS, true)
            && $this->media()->whereKey($media->id)->exists();
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'media_share_items')
            ->withPivot('sort_order')
            ->orderBy('media_share_items.sort_order');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
