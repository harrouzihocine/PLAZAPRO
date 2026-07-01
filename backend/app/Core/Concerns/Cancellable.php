<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use App\Core\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * Turns "delete" into a reversible cancelled status. Every table using this
 * trait carries `status` (default active) and `cancellation_reason`.
 *
 * @property string|RecordStatus $status
 * @property string|null $cancellation_reason
 */
trait Cancellable
{
    public function cancel(string $reason): static
    {
        // saveQuietly() so the generic "updated" audit event does not fire —
        // we log an explicit "cancel" action instead (via LogsActivity).
        $this->forceFill([
            'status' => RecordStatus::Cancelled->value,
            'cancellation_reason' => $reason,
        ])->saveQuietly();

        $this->logActivity('cancel', ['reason' => $reason]);

        return $this;
    }

    public function restore(): static
    {
        $this->forceFill([
            'status' => RecordStatus::Active->value,
            'cancellation_reason' => null,
        ])->saveQuietly();

        $this->logActivity('restore');

        return $this;
    }

    public function isCancelled(): bool
    {
        return $this->getRawStatus() === RecordStatus::Cancelled->value;
    }

    public function isActive(): bool
    {
        return $this->getRawStatus() === RecordStatus::Active->value;
    }

    /** Default lists to active rows. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->getTable().'.status', RecordStatus::Active->value);
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where($this->getTable().'.status', RecordStatus::Cancelled->value);
    }

    private function getRawStatus(): ?string
    {
        $status = $this->getAttribute('status');

        return $status instanceof RecordStatus ? $status->value : $status;
    }
}
