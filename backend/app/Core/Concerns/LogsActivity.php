<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use App\Modules\Analytics\Models\ActivityLog;

/**
 * Writes an append-only audit entry for every meaningful change, automatically.
 * Ordinary create/update fire here via model events; cancel/restore/duplicate
 * are logged explicitly by the Cancellable / HasVersions traits.
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(function ($model): void {
            $model->logActivity('create', ['after' => $model->getAttributes()]);
        });

        static::updated(function ($model): void {
            $model->logActivity('update', [
                'before' => array_intersect_key($model->getOriginal(), $model->getChanges()),
                'after' => $model->getChanges(),
            ]);
        });
    }

    public function logActivity(string $action, array $changes = []): void
    {
        ActivityLog::record($action, $this, $this->scrubHiddenFromAudit($changes));
    }

    /**
     * Never write hidden attributes (passwords, tokens) into the audit log.
     */
    protected function scrubHiddenFromAudit(array $changes): array
    {
        $hidden = array_flip($this->getHidden());

        if ($hidden === []) {
            return $changes;
        }

        foreach (['before', 'after'] as $key) {
            if (isset($changes[$key]) && is_array($changes[$key])) {
                $changes[$key] = array_diff_key($changes[$key], $hidden);
            }
        }

        return $changes;
    }
}
