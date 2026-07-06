<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadata for one unsaved modal draft (see the migration). Plain model —
 * deletable (an admin may clear a stuck draft from the oversight page).
 */
class UserDraft extends Model
{
    protected $fillable = ['user_id', 'draft_key', 'label', 'route'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
