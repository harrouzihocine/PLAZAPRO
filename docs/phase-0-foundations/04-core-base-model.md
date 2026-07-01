# Phase 0 · Step 04 — The Core Base Model (Traceability Built In)

**Goal:** every model in the system extends one base model that carries the foundations —
**automatic activity logging**, the **cancellable (no‑delete)** behaviour, and **versioning**. Because
it is inherited, traceability is automatic everywhere and impossible to forget.

This is the single most important foundation in the backend. Build it carefully and test it.

---

## 1. The `Core` namespace

Create `backend/app/Core/`:

```
app/Core/
├── Models/BaseModel.php
├── Concerns/                # the traits
│   ├── Cancellable.php
│   ├── LogsActivity.php
│   └── HasVersions.php
├── Enums/RecordStatus.php
└── Exceptions/RecordDeletionException.php
```

## 2. `RecordStatus` enum

```php
// app/Core/Enums/RecordStatus.php
namespace App\Core\Enums;

enum RecordStatus: string
{
    case Active    = 'active';
    case Cancelled = 'cancelled';
}
```

## 3. The base model — the no‑delete guarantee

```php
// app/Core/Models/BaseModel.php
namespace App\Core\Models;

use App\Core\Concerns\Cancellable;
use App\Core\Concerns\HasVersions;
use App\Core\Concerns\LogsActivity;
use App\Core\Exceptions\RecordDeletionException;
use Illuminate\Database\Eloquent\Model;

abstract class BaseModel extends Model
{
    use LogsActivity;   // writes who/what/when to the audit log
    use Cancellable;    // "delete"  => status = cancelled (+ reason)
    use HasVersions;    // edits create a new version, keep the old

    // Hard delete is disabled at the framework level:
    public function delete(): bool
    {
        throw new RecordDeletionException(
            'Records are cancelled, never deleted. Use cancel($reason).'
        );
    }

    // Guard against silent bypass via forceDelete/destroy too:
    public function forceDelete(): bool
    {
        throw new RecordDeletionException('Hard delete is disabled on '.static::class);
    }
}
```

```php
// app/Core/Exceptions/RecordDeletionException.php
namespace App\Core\Exceptions;

class RecordDeletionException extends \RuntimeException {}
```

> Together the three traits deliver exactly the **no‑hide, no‑remove, fully‑traceable** behaviour the
> product requires.

## 4. The `Cancellable` trait (delete → cancel)

Gives every record a `status` (active / cancelled) and a `cancellation_reason`.

```php
// app/Core/Concerns/Cancellable.php
namespace App\Core\Concerns;

use App\Core\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Builder;

trait Cancellable
{
    public function cancel(string $reason): static
    {
        $this->forceFill([
            'status'              => RecordStatus::Cancelled->value,
            'cancellation_reason' => $reason,
        ])->saveQuietly();       // saveQuietly to control the audit "action" explicitly

        $this->logActivity('cancel', ['reason' => $reason]);   // provided by LogsActivity

        return $this;
    }

    public function restore(): static
    {
        $this->forceFill([
            'status'              => RecordStatus::Active->value,
            'cancellation_reason' => null,
        ])->saveQuietly();

        $this->logActivity('restore');

        return $this;
    }

    public function isCancelled(): bool
    {
        return $this->status === RecordStatus::Cancelled->value;
    }

    /** Global-ish scopes so lists show active rows by default. */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where($this->getTable().'.status', RecordStatus::Active->value);
    }

    public function scopeCancelled(Builder $q): Builder
    {
        return $q->where($this->getTable().'.status', RecordStatus::Cancelled->value);
    }
}
```

> Every table therefore carries `status` (default `active`) and `cancellation_reason` (nullable). This
> is enforced in the schema — see [`../database/00-schema-overview.md`](../database/00-schema-overview.md).

## 5. The `LogsActivity` trait (append‑only audit)

Records each change with **before‑and‑after** values. Detailed in
[`05-activity-log.md`](05-activity-log.md); the trait is:

```php
// app/Core/Concerns/LogsActivity.php
namespace App\Core\Concerns;

use App\Modules\Analytics\Models\ActivityLog;

trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(fn ($m)  => $m->logActivity('create', ['after' => $m->getAttributes()]));
        static::updated(fn ($m)  => $m->logActivity('update', [
            'before' => array_intersect_key($m->getOriginal(), $m->getChanges()),
            'after'  => $m->getChanges(),
        ]));
    }

    public function logActivity(string $action, array $changes = []): void
    {
        ActivityLog::record($action, $this, $changes);   // see Step 05
    }
}
```

## 6. The `HasVersions` trait (new version, not overwrite)

Keeps superseded versions instead of overwriting, for records that need full history/immutability
(financial rows, units). Implemented via a self‑referencing `supersedes_id`:

```php
// app/Core/Concerns/HasVersions.php
namespace App\Core\Concerns;

use Illuminate\Support\Facades\DB;

trait HasVersions
{
    /**
     * Correct an immutable record: cancel the original and insert a new row
     * that links back to it via supersedes_id. Both remain in history.
     */
    public function supersedeWith(array $attributes, string $reason): static
    {
        return DB::transaction(function () use ($attributes, $reason) {
            $this->cancel($reason);                       // original -> cancelled (Cancellable)

            $replacement = static::create(array_merge(
                $this->replicate()->getAttributes(),
                $attributes,
                ['supersedes_id' => $this->getKey(), 'status' => 'active', 'cancellation_reason' => null],
            ));

            $replacement->logActivity('duplicate', ['supersedes_id' => $this->getKey(), 'reason' => $reason]);

            return $replacement;
        });
    }

    public function supersedes()  { return $this->belongsTo(static::class, 'supersedes_id'); }
    public function supersededBy(){ return $this->hasOne(static::class, 'supersedes_id'); }
}
```

> **Three distinct patterns, one base model** (spelled out in
> [`../database/00-schema-overview.md`](../database/00-schema-overview.md)):
> - **`Cancellable`** — "delete" becomes `status = cancelled` (+ reason).
> - **`LogsActivity`** — ordinary edits are logged with before/after; nothing is hidden.
> - **`HasVersions`** — where immutability matters (versements, units), corrections **cancel + duplicate**
>   with `supersedes_id`, so the full chain stays in history.

## 7. Every model extends `BaseModel`

From here on, **no model extends `Illuminate\Database\Eloquent\Model` directly** — they all extend
`App\Core\Models\BaseModel`. Cast `status` to the enum in each model:

```php
protected function casts(): array
{
    return ['status' => \App\Core\Enums\RecordStatus::class];
}
```

## 8. Test the guarantee (write this test now)

```php
// tests/Feature/Core/NoDeleteTest.php  (Pest)
it('refuses to hard-delete a record', function () {
    $unit = Unit::factory()->create();
    expect(fn () => $unit->delete())->toThrow(RecordDeletionException::class);
});

it('cancels instead of deleting and keeps the row', function () {
    $unit = Unit::factory()->create();
    $unit->cancel('duplicate entry');
    expect($unit->fresh()->isCancelled())->toBeTrue()
        ->and(Unit::withoutGlobalScopes()->find($unit->id))->not->toBeNull();
});
```

---

## Checklist / gate

- [ ] `app/Core/` exists with `BaseModel` and the three traits.
- [ ] Calling `$model->delete()` **throws**; `cancel($reason)` sets `status = cancelled` + reason.
- [ ] `supersedeWith()` cancels the original and creates a linked replacement in one transaction.
- [ ] Every domain model extends `BaseModel` and casts `status` to `RecordStatus`.
- [ ] The no‑delete + cancel tests pass.

**Next:** [`05-activity-log.md`](05-activity-log.md)
