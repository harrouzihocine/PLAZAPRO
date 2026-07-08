<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Models;

use App\Core\Models\BaseModel;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientProject;
use App\Modules\Pipeline\Enums\CallRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A click-to-call handoff from the web app to the agent's phone (the Android
 * shell). Lifecycle: sent (pushed to the phone) → dialed (the shell opened the
 * dialer) → logged / dismissed (the log-call prompt was answered). At most one
 * open request per user+client — re-clicking re-pushes the same row.
 */
class CallRequest extends BaseModel
{
    protected $fillable = [
        'user_id', 'client_id', 'client_project_id', 'phone', 'status', 'dialed_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => CallRequestStatus::class,
            'dialed_at' => 'datetime',
        ]);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'client_project_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn(
            'status',
            array_column(CallRequestStatus::openCases(), 'value'),
        );
    }

    /**
     * The SPA deep link both notifications carry: the project timeline when one
     * was resolved at send time, else the client file. `?logcall=` drives the
     * frontend's auto-open of the log-call modal (and the change-the-next-action
     * step when the pending plan isn't a call).
     */
    public function link(): string
    {
        $base = $this->client_project_id !== null
            ? "/clients/{$this->client_id}/projects/{$this->client_project_id}"
            : "/clients/{$this->client_id}";

        return $base.'?logcall='.$this->id;
    }
}
