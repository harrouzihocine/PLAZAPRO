<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Enums;

enum ConversationType: string
{
    case Direct = 'direct';   // exactly two people
    case Group = 'group';     // named, many people
    case Project = 'project'; // a client project's own thread — participants = its contributors
}
