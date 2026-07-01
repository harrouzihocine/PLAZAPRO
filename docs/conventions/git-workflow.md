# Conventions — Git Workflow

Keep `main` **always working**. Build each feature on its own short‑lived branch and merge through a
reviewed pull request (guide §7.2–7.3).

---

## Branching

```
main                                  # always deployable
 └── feature/inventory-stacking-plan
 └── feature/client-desire-matching
 └── fix/versement-rounding
```

- Branch off `main`; keep branches **short‑lived** (a single vertical slice).
- `feature/*` for new work, `fix/*` for corrections, `chore/*` for tooling/infra.
- Rebase or merge `main` in regularly to avoid drift; delete the branch after merge.

## Commit style

Short, present tense, **scoped** (Conventional‑Commits‑style):

```
feat(inventory): add reservation hold with 48h auto-expiry
fix(payments): correct partial cancellation total
chore(ci): run pint --test on every push
```

- Scope = the module/area (`inventory`, `payments`, `ci`).
- One logical change per commit; keep them reviewable.

## Pull requests

- Open a PR early; describe **what** and **why**, link the phase/slice.
- CI must be green (Pint/ESLint + Pest/Vitest — [`../phase-0-foundations/11-ci-pipeline.md`](../phase-0-foundations/11-ci-pipeline.md)).
- At least **one approving review** before merge; no direct pushes to `main` (branch protection).

## Definition of Done (the merge gate — guide §7.3)

A feature is **done** only when *all* are true:

- [ ] **Works end to end** — UI, API and database, on phone and desktop, in **both themes**.
- [ ] **Respects the foundations** — permissions checked, Actions used for logic, nothing deletes,
      activity logged.
- [ ] **Tested** — at least one feature test for the happy path *and* the key rule (e.g. the 48‑hour expiry).
- [ ] **Formatted and reviewed** — Pint/ESLint clean, approved in a pull request.

## How to know a phase is finished

You can open the app, use the new feature on a phone and on desktop, in both themes, with permissions
enforced and every action traceable — and the tests for it pass. Then move to the next phase.
