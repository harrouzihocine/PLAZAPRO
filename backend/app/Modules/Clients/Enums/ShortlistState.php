<?php

declare(strict_types=1);

namespace App\Modules\Clients\Enums;

/**
 * A shortlisted property's journey across the follow-up phases. One shortlist_item
 * row advances through these states: shortlisted at the office visit, then the site
 * agent records the in-site outcome, then the deal is closed per property.
 */
enum ShortlistState: string
{
    case Shortlisted = 'shortlisted';
    case NotVisited = 'not_visited';
    case VisitedInterested = 'visited_interested';
    case VisitedNotInterested = 'visited_not_interested';
    case Won = 'won';
    case Lost = 'lost';
}
