<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Enums;

enum ReminderState: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Cancelled = 'cancelled';
}
