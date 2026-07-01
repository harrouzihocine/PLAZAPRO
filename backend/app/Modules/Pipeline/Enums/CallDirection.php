<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Enums;

enum CallDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
