<?php

declare(strict_types=1);

namespace App\Modules\Web\Models;

use App\Core\Models\BaseModel;
use App\Modules\Clients\Models\Client;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\User;
use App\Modules\Web\Enums\WebLeadStatus;
use App\Modules\Web\Enums\WebLeadType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A visitor's submission from the public showcase — the staging row between
 * the anonymous internet and the CRM. Created by guests (no created_by;
 * the audit log records a null actor), owned by web.leads holders, and
 * linked to the real Client once converted.
 */
class WebLead extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'name', 'phone', 'message', 'type', 'location_id', 'unit_id',
        'preferred_date', 'preferred_time', 'locale',
        'source_url', 'user_agent', 'ip_hash',
        'lead_status', 'converted_client_id', 'handled_by', 'handled_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => WebLeadType::class,
            'lead_status' => WebLeadStatus::class,
            'preferred_date' => 'date',
            'handled_at' => 'datetime',
        ]);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function convertedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'converted_client_id');
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
