<?php

declare(strict_types=1);

namespace App\Modules\Clients\Models;

use App\Core\Models\BaseModel;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A prospective or existing buyer. Source and rating are dynamic-list items; the
 * assigned agent is a user whose role is flagged is_agent. Clients are cancelled
 * (no-delete) and audited (BaseModel: Cancellable + LogsActivity + HasVersions).
 */
class Client extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'first_name', 'last_name', 'phone', 'email',
        'source_id', 'rating_id', 'assigned_agent_id', 'notes',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(DynamicListItem::class, 'source_id');
    }

    public function rating(): BelongsTo
    {
        return $this->belongsTo(DynamicListItem::class, 'rating_id');
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(ClientProject::class);
    }

    /** The client-level desire (the one not tied to a specific deal). */
    public function desire(): HasOne
    {
        return $this->hasOne(Desire::class)->whereNull('client_project_id');
    }
}
