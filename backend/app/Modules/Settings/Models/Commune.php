<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use App\Core\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A commune (municipality) belonging to a wilaya. `daira_name` keeps the
 * district label from the official division for reference.
 */
class Commune extends BaseModel
{
    use HasFactory;

    protected $fillable = ['wilaya_id', 'name', 'daira_name'];

    public function wilaya(): BelongsTo
    {
        return $this->belongsTo(Wilaya::class);
    }
}
