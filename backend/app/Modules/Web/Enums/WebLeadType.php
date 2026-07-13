<?php

declare(strict_types=1);

namespace App\Modules\Web\Enums;

/**
 * What the visitor asked for on the public site: interest in a unit/project,
 * an office visit, a plain call-back from the contact section, or a desire —
 * "I didn't find it, here is what I'm looking for" with structured criteria.
 */
enum WebLeadType: string
{
    case Interest = 'interest';
    case VisitRequest = 'visit_request';
    case Callback = 'callback';
    case Desire = 'desire';
}
