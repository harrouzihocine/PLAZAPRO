<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use App\Core\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * Manages the reversible record-status lifecycle in place of hard deletes. Every
 * table using this trait carries `status` (default active) and
 * `cancellation_reason`. Two off-active states:
 *   - archive()   → hidden but reversible (reactivate() brings it back);
 *   - cancel()    → the terminal "removed" state (restore() can still revive it).
 * Both drop out of the default active() scope, so neither shows in normal lists.
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

    /** Put the record away: hidden from active lists, but reversible. */
    public function archive(): static
    {
        $this->forceFill([
            'status' => RecordStatus::Archived->value,
        ])->saveQuietly();

        $this->logActivity('archive');

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

    /** Bring an archived record back to active. */
    public function reactivate(): static
    {
        $this->forceFill([
            'status' => RecordStatus::Active->value,
        ])->saveQuietly();

        $this->logActivity('reactivate');

        return $this;
    }

    public function isCancelled(): bool
    {
        return $this->getRawStatus() === RecordStatus::Cancelled->value;
    }

    public function isArchived(): bool
    {
        return $this->getRawStatus() === RecordStatus::Archived->value;
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

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where($this->getTable().'.status', RecordStatus::Archived->value);
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
