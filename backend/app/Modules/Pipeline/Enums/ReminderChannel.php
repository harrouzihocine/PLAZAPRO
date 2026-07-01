<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Enums;

enum ReminderChannel: string
{
    case InApp = 'in_app';
    case Email = 'email';
}
