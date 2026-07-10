<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Enums;

/**
 * The fate of a beyond-window office-visit plan (null on the column = the plan
 * was in-window and never needed anyone's approval).
 */
enum NextActionApproval: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Denied = 'denied';
    // The manager answered with their own date: the plan was superseded by a
    // corrected version (CorrectNextAction), which needs no approval.
    case Rescheduled = 'rescheduled';
}
