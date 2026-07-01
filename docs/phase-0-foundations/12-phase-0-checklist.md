# Phase 0 · Step 12 — Foundation Checklist (the gate)

Resist the urge to build features until **every** item below is true. Phase 0 is the platform
everything else stands on; getting it right makes the rest of the build steady and predictable.

This reproduces the guide's **§10.3 Foundation Checklist** and maps each item to the step that delivers it.

---

## The gate

- [ ] **Containers start with one command; frontend and backend communicate.**
  → [`02-docker-stack.md`](02-docker-stack.md) · [`08-vue-install.md`](08-vue-install.md)
  *`docker compose up -d` brings up nginx/app/queue/node/mysql/redis/mailpit; the SPA at `:5173`
  reaches `/api/v1/*` through the proxy.*

- [ ] **Login works; routes are permission‑protected; the agent flag drives visit assignment.**
  → [`06-auth-and-rbac.md`](06-auth-and-rbac.md)
  *Sanctum SPA login; `can:<perm>` on write routes (403 without permission); `is_agent` roles are the
  only visit‑assignment candidates, enforced in the Action.*

- [ ] **Nothing can be hard‑deleted; corrections cancel‑and‑duplicate; the activity log records every action.**
  → [`04-core-base-model.md`](04-core-base-model.md) · [`05-activity-log.md`](05-activity-log.md)
  *`delete()` throws; `cancel($reason)` + `supersedeWith()` work; append‑only `activity_log` captures
  who/what/when/before→after/IP and cannot be mutated.*

- [ ] **Dynamic settings power dropdowns; media attaches at project and unit level.**
  → *Structure defined in [`../database/01-settings.md`](../database/01-settings.md) and
  [`../database/02-inventory.md`](../database/02-inventory.md); the working features ship in
  Phase 1 & Phase 2. In Phase 0, confirm the `dynamic_lists`/`dynamic_list_items` and polymorphic
  `media` designs are agreed.*

- [ ] **Both themes work; layouts are responsive on phone and desktop.**
  → [`09-theming-and-appshell.md`](09-theming-and-appshell.md)
  *Gold/white + gold/black toggle (persisted); `AppShell` shows sidebar on desktop, bottom nav on
  mobile; single‑column reflow on phone.*

- [ ] **Tests and style checks run automatically on every push.**
  → [`11-ci-pipeline.md`](11-ci-pipeline.md)
  *CI runs Pint + Pest and ESLint/Prettier + Vitest on every push/PR; `main` is branch‑protected.*

---

## Extra Phase 0 confirmations (from earlier steps)

- [ ] Docker/Compose/Git installed and verified — [`00-prerequisites-ubuntu.md`](00-prerequisites-ubuntu.md)
- [ ] Mono‑repo layout, `.gitignore`, `.editorconfig`, `.env.example`; `.env` ignored — [`01-repo-and-layout.md`](01-repo-and-layout.md)
- [ ] Laravel API on `/api/v1`, JSON errors, Sanctum installed — [`03-laravel-install.md`](03-laravel-install.md)
- [ ] `app/Core/` base model + three traits; every model extends it — [`04-core-base-model.md`](04-core-base-model.md)
- [ ] `app/Modules/*` skeleton created and autoloading — [`07-modules-skeleton.md`](07-modules-skeleton.md)
- [ ] Security baseline in place (throttling, CORS, headers, validation, upload rules) — [`10-security-baseline.md`](10-security-baseline.md)

---

## When this gate is green

You have a running, themed, secure, fully‑traceable platform with authentication, RBAC, the no‑delete
guarantee, the audit log, and CI — and no product features yet. That is exactly right. Now build
features as tested vertical slices, starting with the settings backbone.

**Next:** [`../database/00-schema-overview.md`](../database/00-schema-overview.md) then
[`../phase-1-settings.md`](../phase-1-settings.md)
