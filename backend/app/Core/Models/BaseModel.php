<?php

declare(strict_types=1);

namespace App\Core\Models;

use App\Core\Concerns\Cancellable;
use App\Core\Concerns\HasVersions;
use App\Core\Concerns\LogsActivity;
use App\Core\Enums\RecordStatus;
use App\Core\Exceptions\RecordDeletionException;
use Illuminate\Database\Eloquent\Model;

/**
 * The base for every domain model. It bakes in traceability so it can never be
 * forgotten: automatic activity logging, the cancellable (no-delete) behaviour,
 * and versioning. Hard delete is disabled at the framework level.
 *
 * Subclasses that need extra casts should merge with parent::casts():
 *   protected function casts(): array {
 *       return array_merge(parent::casts(), ['price' => 'decimal:2']);
 *   }
 */
abstract class BaseModel extends Model
{
    use Cancellable;    // "delete"  => status = cancelled (+ reason)
    use HasVersions;    // corrections create a new version, keep the old
    use LogsActivity;   // writes who/what/when to the audit log

    /**
     * New records start active in-memory too (mirrors the DB default), so a
     * freshly-created model's resource reflects `status: active` without a reload.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active', // RecordStatus::Active
    ];

    protected function casts(): array
    {
        return [
            'status' => RecordStatus::class,
        ];
    }

    /** Hard delete is disabled — records are cancelled, never deleted. */
    public function delete(): bool
    {
        throw new RecordDeletionException(
            'Records are cancelled, never deleted. Use cancel($reason).'
        );
    }

    /** Guard against silent bypass via forceDelete() too. */
    public function forceDelete(): bool
    {
        throw new RecordDeletionException(
            'Hard delete is disabled on '.static::class.'.'
        );
    }
}
