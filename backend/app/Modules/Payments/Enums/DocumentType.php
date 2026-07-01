<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * The kind of branded document generated for a record. Drives the numbering
 * prefix and the Blade template used to render the PDF.
 */
enum DocumentType: string
{
    case Receipt = 'receipt';
    case Contract = 'contract';
    case Quote = 'quote';
    case Schedule = 'schedule';

    /** Prefix used when allocating a human document number (e.g. REC-2026-000123). */
    public function numberPrefix(): string
    {
        return match ($this) {
            self::Receipt => 'REC',
            self::Contract => 'CTR',
            self::Quote => 'QUO',
            self::Schedule => 'SCH',
        };
    }

    /** Blade template key rendered for this document type. */
    public function template(): string
    {
        return "documents.{$this->value}";
    }
}
