<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Keeps superseded versions instead of overwriting, for records that must stay
 * immutable (financial rows, units). A correction cancels the original and
 * inserts a new row linked back to it via `supersedes_id` — both stay in history.
 */
trait HasVersions
{
    /**
     * Correct an immutable record: cancel the original and insert a replacement
     * that carries the changed attributes and links back via supersedes_id.
     */
    public function supersedeWith(array $attributes, string $reason): static
    {
        return DB::transaction(function () use ($attributes, $reason) {
            $this->cancel($reason); // original -> cancelled (Cancellable)

            $replacement = static::query()->create(array_merge(
                $this->replicate()->getAttributes(),
                $attributes,
                [
                    'supersedes_id' => $this->getKey(),
                    'status' => 'active',
                    'cancellation_reason' => null,
                ],
            ));

            $replacement->logActivity('duplicate', [
                'supersedes_id' => $this->getKey(),
                'reason' => $reason,
            ]);

            return $replacement;
        });
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(static::class, 'supersedes_id');
    }

    public function supersededBy(): HasOne
    {
        return $this->hasOne(static::class, 'supersedes_id');
    }
}
