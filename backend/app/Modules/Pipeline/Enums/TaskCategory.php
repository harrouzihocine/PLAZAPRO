<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Enums;

/**
 * What kind of work a task is. Beyond client follow-up, the board carries the
 * agency's marketing/growth routine: content production (TikTok/reels),
 * scheduled publications, training ("do this formation / read this") and
 * admin chores.
 */
enum TaskCategory: string
{
    case FollowUp = 'follow_up';
    case Prospecting = 'prospecting';
    case Content = 'content';
    case Publication = 'publication';
    case Training = 'training';
    case Admin = 'admin';
}
