<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Enums;

enum VisitType: string
{
    case Office = 'office';
    // In-site (field) visit to a specific property. Was 'apartment'; renamed to
    // cover apartments, boxes and commercial "locals" alike.
    case InSite = 'in_site';
}
