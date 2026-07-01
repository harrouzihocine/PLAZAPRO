<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Enums\DocumentType;
use App\Modules\Payments\Models\Document;
use Illuminate\Support\Str;

/**
 * Allocates unique, sequential human document numbers per type and year, e.g.
 * REC-2026-000123. Called inside the generation transaction and takes a row lock
 * on the current maximum so concurrent generation can't collide.
 */
class DocumentNumberGenerator
{
    public function next(DocumentType $type): string
    {
        $prefix = $type->numberPrefix();
        $year = now()->year;

        // Zero-padded to 6, so lexical ordering matches numeric ordering.
        $last = Document::query()
            ->where('number', 'like', "{$prefix}-{$year}-%")
            ->lockForUpdate()
            ->orderByDesc('number')
            ->value('number');

        $sequence = $last !== null ? ((int) Str::afterLast($last, '-')) + 1 : 1;

        return sprintf('%s-%d-%06d', $prefix, $year, $sequence);
    }
}
