<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use App\Core\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A role groups permissions. Each user has exactly one role. The is_agent flag
 * decides who can be assigned visits.
 */
class Role extends BaseModel
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'description', 'is_agent'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_agent' => 'boolean',
        ]);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function hasPermission(string $slug): bool
    {
        return $this->relationLoaded('permissions')
            ? $this->permissions->contains('slug', $slug)
            : $this->permissions()->where('slug', $slug)->exists();
    }
}
