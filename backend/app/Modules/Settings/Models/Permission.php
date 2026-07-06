<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A single RBAC permission (slug like "units.interest"). Permissions are seeded
 * configuration, so this is a plain Model (not audited/cancellable).
 */
class Permission extends Model
{
    protected $fillable = ['name', 'slug', 'group', 'description'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
