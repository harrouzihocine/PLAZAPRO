# Testing, Quality & Going Live

Aim for **confidence, not perfection**. A focused set of tests on the rules that matter is worth more
than chasing a coverage number (guide §10).

---

## 1. Testing strategy

### Backend — Pest / PHPUnit feature tests hitting the API

Test the **happy path plus each key rule**. The guide names these rules explicitly:

| Rule | Where | Doc |
|------|-------|-----|
| **Next‑action match** (desire → inventory; enforced next action) | Phase 3 | [`phase-3-clients-pipeline.md`](phase-3-clients-pipeline.md) |
| **48‑hour expiry** (reservation hold) | Phase 2 | [`phase-2-inventory-media.md`](phase-2-inventory-media.md) |
| **No‑delete** (delete throws; cancel keeps the row) | Phase 0 | [`phase-0-foundations/04-core-base-model.md`](phase-0-foundations/04-core-base-model.md) |
| **Archive‑only‑without‑payments** | Phase 4 | [`phase-4-payments-documents.md`](phase-4-payments-documents.md) |
| **Cancel‑and‑duplicate correction** (versement) | Phase 4 | [`database/04-payments.md`](database/04-payments.md) |
| **Agent‑only visit assignment** (`is_agent`) | Phase 3 | [`phase-0-foundations/06-auth-and-rbac.md`](phase-0-foundations/06-auth-and-rbac.md) |
| **RBAC** (missing permission → 403) | Phase 0 | [`phase-0-foundations/06-auth-and-rbac.md`](phase-0-foundations/06-auth-and-rbac.md) |
| **Audit written automatically** (append‑only) | Phase 0 | [`phase-0-foundations/05-activity-log.md`](phase-0-foundations/05-activity-log.md) |
| **Versement rounding** (no float drift) | Phase 4 | [`database/04-payments.md`](database/04-payments.md) |

- Use factories; hit real routes with `actingAs`; assert JSON shape + DB state + audit rows.
- Run against a **separate test database** (`plaza_test`); migrate fresh per run (`RefreshDatabase`).

### Frontend — Vitest

- Components and composables (`useApi`, `useTheme`, stores).
- A few **end‑to‑end checks** for the critical flows: capture a client, reserve a unit, record a payment.

### Continuous integration

Run the **whole suite and the style checks on every push**, so `main` is always green —
[`phase-0-foundations/11-ci-pipeline.md`](phase-0-foundations/11-ci-pipeline.md).

### Commands

```bash
docker compose exec app php artisan test          # Pest/PHPUnit
docker compose exec app ./vendor/bin/pint --test  # style gate
docker compose exec node npm run test             # Vitest
docker compose exec node npm run lint             # ESLint
```

---

## 2. Going live (guide §10.2)

Production uses the **same container approach**, tightened for security and performance. Keep
configuration in **environment variables, never in code**. Full detail in
[`phase-7-hardening-launch.md`](phase-7-hardening-launch.md).

- **Build optimised images** — compiled Vue assets served as static files; PHP with caching (config,
  routes, views) enabled.
- **Use real services** — a managed or hardened MySQL with automated backups, object storage with a CDN
  for media, and **HTTPS everywhere**.
- **Run migrations on deploy** — automatically and safely, **with a backup taken first**.
- **Monitor** — log errors centrally and watch the queue, so issues surface before users report them.

---

## 3. The bottom line

Get **Phase 0** right and the rest of the build is steady, predictable work: pick the next feature,
build it as a tested vertical slice that respects the foundations, ship it, repeat. That is how a small
team — with AI assistance — builds a genuinely world‑class, extensible, beautifully themed CRM from
zero, without the structure ever drifting.

See the full foundation gate in
[`phase-0-foundations/12-phase-0-checklist.md`](phase-0-foundations/12-phase-0-checklist.md).
