# Conventions — Coding Standards

Shared standards let several people — and AI assistance — work on the same codebase without it
drifting. **Automate them so they are enforced, not just hoped for** (guide §7.1).

---

## PHP (backend)

- **Follow PSR‑12.** Run **Laravel Pint** to format automatically **before every commit**.
- `pint.json` at `backend/` pins the preset (`laravel`); CI runs `pint --test` (fails on unformatted code).
- Prefer typed properties, return types, and `readonly` where sensible.
- **Thin controllers, one‑job Actions.** Business logic never lives in a controller or a model method
  that spans concerns — it lives in an Action ([`vertical-slice-recipe.md`](vertical-slice-recipe.md)).
- **FormRequests** for all validation; **API Resources** for all JSON output.
- **Enums** (PHP 8.1+) for fixed sets (`UnitStatus`, `VisitType`, `RecordStatus`).
- Wrap multi‑step writes in `DB::transaction`.
- `$fillable` allow‑lists on user‑writable models; privileged fields set only by Actions.

## JavaScript / Vue (frontend)

- **ESLint** for correctness, **Prettier** for formatting — **on save and in CI**.
- Vue 3 **Composition API** with `<script setup>`; components small and focused.
- **No business rules in the frontend** — it presents data and calls the API via `useApi`.
- State in **Pinia** stores, one per feature; network in the feature's `api.js` using the shared `useApi`.
- Colours only via **design tokens** (Tailwind classes bound to CSS variables) — never raw hex.
- Indentation: 2 spaces for JS/Vue/CSS, 4 for PHP (see root `.editorconfig`).

## One source of truth

These tools are **configured in the repo** (`pint.json`, `.eslintrc`, `.prettierrc`, `.editorconfig`),
so every machine and every AI session formats identically. Do not override them locally.

## Naming (from [`../00-overview-and-conventions.md`](../00-overview-and-conventions.md) §4)

| Thing | Rule |
|-------|------|
| Models | singular PascalCase (`Unit`) |
| Actions | verb phrase (`ReserveUnit`) |
| Controllers | `NameController`, thin |
| FormRequests | `VerbResourceRequest` |
| API Resources | `ModelResource` |
| Tables | plural snake_case; FKs `singular_id` |
| API routes | versioned + plural (`/api/v1/units`) |
| Vue components | PascalCase; base components `Base*` |
| Pinia stores | `useXStore` |

## Commands

```bash
docker compose exec app ./vendor/bin/pint          # format PHP
docker compose exec app ./vendor/bin/pint --test   # check only (CI)
docker compose exec node npm run lint              # ESLint
docker compose exec node npm run format            # Prettier check
```

See also [`git-workflow.md`](git-workflow.md) and [`../phase-0-foundations/11-ci-pipeline.md`](../phase-0-foundations/11-ci-pipeline.md).
