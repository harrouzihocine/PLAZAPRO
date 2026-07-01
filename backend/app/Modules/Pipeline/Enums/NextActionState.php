<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Enums;

enum NextActionState: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Cancelled = 'cancelled';
}
