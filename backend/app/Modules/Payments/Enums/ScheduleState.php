<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * The payment progress of an instalment (payment_schedules.state). DERIVED
 * server-side from allocated versements and the due date — never client-set.
 * Distinct from the base record `status` (active|cancelled).
 */
enum ScheduleState: string
{
    case Pending = 'pending';   // nothing paid yet, not overdue
    case Partial = 'partial';   // some but not all paid
    case Paid = 'paid';         // fully settled
    case Overdue = 'overdue';   // past due_date and not fully paid
    case Cancelled = 'cancelled'; // the instalment was voided
}
