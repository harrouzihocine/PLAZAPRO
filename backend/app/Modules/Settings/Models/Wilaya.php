<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use App\Core\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An Algerian wilaya (province) — the top of the geographic hierarchy. `code`
 * is the official numeric code ("01".."58"). Each wilaya hasMany communes.
 * Seeded by WilayaCommuneSeeder; manageable in-app via the Settings screen.
 */
class Wilaya extends BaseModel
{
    use HasFactory;

    protected $fillable = ['code', 'name'];

    public function communes(): HasMany
    {
        return $this->hasMany(Commune::class);
    }

    /** Active communes in display order — exactly what dependent dropdowns consume. */
    public function activeCommunes(): HasMany
    {
        return $this->communes()->active()->orderBy('name');
    }
}
