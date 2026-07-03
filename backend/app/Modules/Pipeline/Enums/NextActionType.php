<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Enums;

enum NextActionType: string
{
    case Call = 'call';
    case OfficeVisit = 'office_visit';
    // In-site (field) visit. Was 'apartment_visit'; renamed to match VisitType::InSite.
    case InSiteVisit = 'in_site_visit';
}
