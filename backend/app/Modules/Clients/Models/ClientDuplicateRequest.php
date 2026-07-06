<?php

declare(strict_types=1);

namespace App\Modules\Clients\Models;

use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supervised duplicate-phone resolution (see the migration). Plain workflow
 * record — not a versioned/audited domain entity.
 */
class ClientDuplicateRequest extends Model
{
    protected $fillable = [
        'existing_client_id', 'requested_by', 'attempted_data', 'status',
        'resolved_by', 'resolution', 'shared_project_id', 'spawned_project_id',
        'share_details', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'attempted_data' => 'array',
            'share_details' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function existingClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'existing_client_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function sharedProject(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'shared_project_id');
    }

    /** The NEW project spawned for the finder (fork_project resolution). */
    public function spawnedProject(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'spawned_project_id');
    }
}
