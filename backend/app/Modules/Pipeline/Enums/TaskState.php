<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Enums;

enum TaskState: string
{
    case Open = 'open';
    case Done = 'done';
    case Cancelled = 'cancelled';
}
