<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Enums;

enum CallRequestStatus: string
{
    case Sent = 'sent';
    case Dialed = 'dialed';
    case Logged = 'logged';
    case Dismissed = 'dismissed';

    /**
     * Still awaiting an outcome — the states the reminder machinery acts on.
     * Single source: CallRequest::scopeOpen derives its whereIn from this.
     *
     * @return list<self>
     */
    public static function openCases(): array
    {
        return [self::Sent, self::Dialed];
    }

    public function isOpen(): bool
    {
        return in_array($this, self::openCases(), true);
    }
}
