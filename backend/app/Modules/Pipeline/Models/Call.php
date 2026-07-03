<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Models;

use App\Core\Models\BaseModel;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Enums\CallDirection;
use App\Modules\Settings\Models\DynamicListItem;
use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A logged phone interaction. Logging a call is a completed interaction, so it
 * always leaves a next action (see LogCall).
 */
class Call extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'client_id', 'client_project_id', 'agent_id', 'direction',
        'outcome_id', 'notes', 'topics', 'called_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'direction' => CallDirection::class,
            'topics' => 'array',
            'called_at' => 'datetime',
        ]);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function clientProject(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(DynamicListItem::class, 'outcome_id');
    }

    public function nextActions(): MorphMany
    {
        return $this->morphMany(NextAction::class, 'source');
    }
}
