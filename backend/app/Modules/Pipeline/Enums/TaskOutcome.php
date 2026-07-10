<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Enums;

/**
 * How a task ended, captured by the completion report: done as planned,
 * partially done, or done but with problems along the way.
 */
enum TaskOutcome: string
{
    case Full = 'full';
    case Partial = 'partial';
    case Issues = 'issues';
}
