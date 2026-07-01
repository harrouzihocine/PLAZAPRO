<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Enums;

enum VisitType: string
{
    case Office = 'office';
    case Apartment = 'apartment';
}
