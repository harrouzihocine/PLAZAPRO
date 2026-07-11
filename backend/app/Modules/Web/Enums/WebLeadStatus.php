<?php

declare(strict_types=1);

namespace App\Modules\Web\Enums;

/**
 * Inbox workflow state of a web lead. `converted` is terminal-successful
 * (a real Client exists); `spam` is terminal-negative. Distinct from the
 * BaseModel record `status` (active/cancelled).
 */
enum WebLeadStatus: string
{
    case New = 'new';
    case Handled = 'handled';
    case Converted = 'converted';
    case Spam = 'spam';
}
