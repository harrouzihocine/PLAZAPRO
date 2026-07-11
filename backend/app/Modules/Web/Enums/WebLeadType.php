<?php

declare(strict_types=1);

namespace App\Modules\Web\Enums;

/**
 * What the visitor asked for on the public site: interest in a unit/project,
 * an office visit, or a plain call-back from the contact section.
 */
enum WebLeadType: string
{
    case Interest = 'interest';
    case VisitRequest = 'visit_request';
    case Callback = 'callback';
}
