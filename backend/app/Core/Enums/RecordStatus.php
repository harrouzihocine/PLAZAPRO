<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * The record-level lifecycle state carried by every domain model (BaseModel).
 * This is the "no-delete" state — distinct from any commercial status a model
 * may also have (e.g. a unit's sale_status of available/reserved/sold).
 *
 *   active    → the live, visible state.
 *   archived  → put away and hidden, but reversible: reactivate() → active.
 *   cancelled → the terminal "removed" state; the row and its history are kept
 *               for audit, but the UI treats it as gone.
 */
enum RecordStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
    case Cancelled = 'cancelled';
}
