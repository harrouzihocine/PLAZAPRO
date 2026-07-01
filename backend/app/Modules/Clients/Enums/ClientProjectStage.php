<?php

declare(strict_types=1);

namespace App\Modules\Clients\Enums;

/**
 * The stage of a deal (client_project) in the sales pipeline. Movement between
 * stages is constrained: you go forward, or drop out to `lost`; `won`/`lost` are
 * terminal. The single source of truth for allowed transitions.
 */
enum ClientProjectStage: string
{
    case Lead = 'lead';
    case Negotiating = 'negotiating';
    case Reserved = 'reserved';
    case Won = 'won';
    case Lost = 'lost';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Lead => [self::Negotiating, self::Reserved, self::Lost],
            self::Negotiating => [self::Reserved, self::Won, self::Lost],
            self::Reserved => [self::Won, self::Lost],
            self::Won, self::Lost => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }
}
