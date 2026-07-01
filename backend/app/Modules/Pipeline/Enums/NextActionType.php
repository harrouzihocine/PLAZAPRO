<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Enums;

enum NextActionType: string
{
    case Call = 'call';
    case OfficeVisit = 'office_visit';
    case ApartmentVisit = 'apartment_visit';
    case FollowUp = 'follow_up';
    case SendDocs = 'send_docs';
}
