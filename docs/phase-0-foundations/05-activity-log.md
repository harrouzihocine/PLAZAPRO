# Phase 0 · Step 05 — The Activity Log

**Goal:** a single **append‑only** table that records every meaningful action. It is written
automatically by the base model (Step 04) and is **never updated or deleted**. An administrator can
search it by user, date, entity or action.

---

## 1. The `activity_log` table (guide Table 4.1)

| Column | Holds |
|--------|-------|
| `user_id` | Who performed the action (and their role at the time) |
| `role_at_time` | Snapshot of the actor's role name (roles can change later) |
| `action` | `create · update · cancel · restore · duplicate · view · login · export` |
| `subject_type` / `subject_id` | Which record was affected (polymorphic) |
| `changes` | Before and after values for an edit (JSON) |
| `ip_address` / `user_agent` | Where the action came from |
| `created_at` | Server timestamp (immutable) |

> **Append‑only:** there is intentionally **no `updated_at`** and no update/delete path. Rows are only
> ever inserted.

## 2. Migration

```php
// database/migrations/xxxx_create_activity_log_table.php
Schema::create('activity_log', function (Blueprint $t) {
    $t->id();
    $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
    $t->string('role_at_time')->nullable();
    $t->string('action')->index();                 // create/update/cancel/restore/duplicate/view/login/export
    $t->nullableMorphs('subject');                 // subject_type + subject_id (+ index)
    $t->json('changes')->nullable();               // before/after
    $t->string('ip_address', 45)->nullable();
    $t->text('user_agent')->nullable();
    $t->timestamp('created_at')->useCurrent();     // no updated_at — append-only

    // nullableMorphs() already indexed (subject_type, subject_id); add the query paths we filter on:
    $t->index(['user_id', 'created_at']);
    $t->index(['action', 'created_at']);
});
```

## 3. The model (lives in the Analytics module)

```php
// app/Modules/Analytics/Models/ActivityLog.php
namespace App\Modules\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ActivityLog extends Model      // NOT BaseModel — the log itself is never audited/cancelled
{
    public const UPDATED_AT = null;  // append-only: disable updated_at
    protected $table = 'activity_log';
    protected $guarded = [];
    protected $casts = ['changes' => 'array'];

    public static function record(string $action, $subject = null, array $changes = []): self
    {
        $user    = Auth::user();
        $request = request();

        return static::create([
            'user_id'      => $user?->id,
            'role_at_time' => $user?->role?->name,
            'action'       => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id'   => $subject?->getKey(),
            'changes'      => $changes ?: null,
            'ip_address'   => $request?->ip(),
            'user_agent'   => $request?->userAgent(),
        ]);
    }

    // Hard-block mutation to keep the log immutable:
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \RuntimeException('The activity log is append-only.');
    }

    public function delete(): bool
    {
        throw new \RuntimeException('The activity log is append-only.');
    }
}
```

> `ActivityLog::record()` is the exact method the `LogsActivity` trait calls in Step 04. Auth events
> (`login`) are logged from the auth controller (Step 06); `export` is logged from any export Action;
> `view` is logged only where the product requires access tracking (e.g. viewing a client file), never
> on every list request (that would flood the log).

## 4. Admin search endpoint

Expose a **read‑only, permission‑guarded** endpoint so an admin can query the log. No write routes exist.

```php
// app/Modules/Analytics/routes.php  (inside the v1 group)
Route::middleware(['auth:sanctum', 'can:audit.view'])
    ->get('/audit', [AuditController::class, 'index']);   // filters: user_id, action, subject_type, from, to
```

`AuditController@index` returns a paginated `ActivityLogResource` collection, filterable by user, date
range, entity type and action. The admin audit **feed UI** is built in
[`../phase-6-analytics-audit.md`](../phase-6-analytics-audit.md).

## 5. Test it

```php
it('logs a create automatically', function () {
    $unit = Unit::factory()->create();
    $log  = ActivityLog::latest('id')->first();
    expect($log->action)->toBe('create')
        ->and($log->subject_type)->toBe(Unit::class)
        ->and($log->subject_id)->toBe($unit->id);
});

it('records before/after on update', function () {
    $unit = Unit::factory()->create(['price' => 100]);
    $unit->update(['price' => 120]);
    $log = ActivityLog::where('action', 'update')->latest('id')->first();
    expect($log->changes['after']['price'])->toBe(120);
});

it('refuses to mutate the log', function () {
    $log = ActivityLog::factory()->create();
    expect(fn () => $log->update(['action' => 'x']))->toThrow(RuntimeException::class);
});
```

---

## Checklist / gate

- [ ] `activity_log` table created with the columns above and useful indexes; **no `updated_at`**.
- [ ] Creating/updating/cancelling any `BaseModel` writes a log row automatically.
- [ ] `changes` captures before→after for updates.
- [ ] The log rejects `update()`/`delete()` — it is truly append‑only.
- [ ] `GET /api/v1/audit` is permission‑guarded (`can:audit.view`) and read‑only.

**Next:** [`06-auth-and-rbac.md`](06-auth-and-rbac.md)
