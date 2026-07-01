<?php

declare(strict_types=1);

namespace App\Modules\Collaboration\Enums;

enum MessageType: string
{
    case Text = 'text';
    case Image = 'image';
    case Voice = 'voice';
    case File = 'file';
    case System = 'system'; // e.g. "X shared a record", "Y joined"
}
