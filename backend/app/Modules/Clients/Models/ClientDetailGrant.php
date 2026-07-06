<?php

declare(strict_types=1);

namespace App\Modules\Clients\Models;

use App\Modules\Settings\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-user unlock of one client's details (see the migration). Plain pivot-ish
 * record.
 */
class ClientDetailGrant extends Model
{
    protected $fillable = ['client_id', 'user_id', 'granted_by'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
