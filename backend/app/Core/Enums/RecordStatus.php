<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * The record-level lifecycle state carried by every domain model (BaseModel).
 * This is the "no-delete" state — distinct from any commercial status a model
 * may also have (e.g. a unit's sale_status of available/reserved/sold).
 */
enum RecordStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';
}
