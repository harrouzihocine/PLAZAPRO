# Conventions — The Vertical‑Slice Recipe

Every feature is built as **one vertical slice**: a thin path from screen to database that actually
works — *not* whole layers in isolation. Ship a slice, gate it, move on. This is the repeatable order
every phase (and every AI session) follows, so later modules mirror earlier ones.

> The same **ten‑step write lifecycle** ([`../00-overview-and-conventions.md`](../00-overview-and-conventions.md) §3)
> describes every write. Learn it once, apply it to every feature.

---

## The order (backend → frontend → test)

For a feature like "reserve a unit":

1. **Migration** — the table(s) the slice needs, with base‑model columns + indexes
   ([`../database/`](../database/)). Migrations only; never hand‑edit the schema.
2. **Model** — extends `App\Core\Models\BaseModel`; relationships, casts (`status` → `RecordStatus`),
   `$fillable` allow‑list, enums. Lives in `app/Modules/<Module>/Models/`.
3. **Action** — one job, the business rule (`ReserveUnit`): validates preconditions, mutates via the
   model inside `DB::transaction`, returns the result. Business logic lives **here**, nowhere else.
4. **Controller** — thin; injects the FormRequest, calls the Action, returns a Resource. No logic.
5. **FormRequest** — validation + `authorize()`; the only trust boundary for input.
6. **API Resource** — shapes the JSON the frontend receives.
7. **Route** — versioned + plural in the module's `routes.php`, guarded by
   `['auth:sanctum', 'can:<permission>']`.
8. **Pinia store + `api.js`** — the feature's state and its API calls (via the shared `useApi`).
9. **Vue view/components** — responsive, themed (tokens only), reuses `Base*` components; a few taps on
   mobile.
10. **Tests** — a feature test for the **happy path** *and* the **key rule** (Pest, hitting the API);
    Vitest for critical components/composables.

Then run Pint/ESLint, open a PR, pass the Definition of Done gate
([`git-workflow.md`](git-workflow.md)), merge.

---

## Skeleton (copy per slice)

```php
// routes.php
Route::middleware(['auth:sanctum','can:units.reserve'])
    ->post('/units/{unit}/reserve', [UnitController::class, 'reserve']);

// Http/Requests/ReserveUnitRequest.php
public function authorize(): bool { return $this->user()->can('units.reserve'); }
public function rules(): array   { return ['client_project_id' => ['nullable','exists:client_projects,id']]; }

// Http/Controllers/UnitController.php  (thin)
public function reserve(ReserveUnitRequest $r, Unit $unit, ReserveUnit $action)
{
    return new UnitResource($action->handle($unit, $r->validated(), $r->user()));
}

// Actions/ReserveUnit.php  (the business rule)
public function handle(Unit $unit, array $data, User $agent): Unit
{
    return DB::transaction(function () use ($unit, $data, $agent) {
        abort_if($unit->sale_status !== 'available', 422, 'Unit is not available.');
        Reservation::create([
            'unit_id' => $unit->id, 'held_by' => $agent->id,
            'client_project_id' => $data['client_project_id'] ?? null,
            'held_at' => now(), 'expires_at' => now()->addHours(48), 'hold_status' => 'active',
        ]);
        $unit->update(['sale_status' => 'reserved']);   // logs activity + versions automatically
        return $unit->fresh();
    });
}
```

```js
// features/inventory/api.js
import { useApi } from '@/composables/useApi'
export const reserveUnit = (id, payload) => useApi().post(`/units/${id}/reserve`, payload)
```

```php
// tests/Feature/Inventory/ReserveUnitTest.php  (the key rule)
it('reserves an available unit for 48h', function () {
    $unit = Unit::factory()->create(['sale_status' => 'available']);
    $this->actingAs(agentUser())->postJson("/api/v1/units/{$unit->id}/reserve")->assertOk();
    expect($unit->fresh()->sale_status)->toBe('reserved')
        ->and(Reservation::latest('id')->first()->expires_at->diffInHours(now()))->toBe(48);
});
```

---

## Why slices, not layers

- Every step ships **something demoable** and testable.
- Small, tested increments are easy to verify and easy to roll back; large layer dumps are neither.
- The structure stays coherent because each slice repeats the same shape.

See [`ai-assisted-workflow.md`](ai-assisted-workflow.md) for doing this efficiently with AI.
