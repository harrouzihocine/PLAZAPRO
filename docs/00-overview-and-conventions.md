# 00 · Overview & Conventions

The single most important document in this playbook. It holds the shape of the system and the rules
every later step obeys. Read it before writing a line of code, and return to it whenever a decision
feels ambiguous.

---

## 1. What you are building

A web application with three parts working together:

- a **Vue 3 single‑page app** for the interface,
- a **Laravel REST API** for the logic,
- **MySQL** for the data,

all running in **Docker** containers on Ubuntu. The result is **responsive** (works well on phones),
**themeable** (gold‑and‑white by day, gold‑and‑black at night), and **extensible** (new features slot
in without disturbing the old ones).

---

## 2. Architecture — the five layers

A request travels **down** through clear layers, each with a single responsibility. Keeping these
boundaries is the most important habit in the whole codebase.

| # | Layer | Responsibility | Never does |
|---|-------|----------------|------------|
| 1 | **Presentation** (Vue) | components, views, Pinia stores, routing; asks the API | contains business rules |
| 2 | **API** (Laravel routes, controllers, requests, resources) | the front door; thin controllers, FormRequest validation, API Resources shape JSON | holds logic in controllers |
| 3 | **Application** (services & actions) | the business logic — each Action does exactly one job (`ReserveUnit`, `RecordVersement`) | reaches the DB without a model |
| 4 | **Domain** (models & modules) | Eloquent models, enums, relationships grouped by feature module — the *meaning* of the system | knows about HTTP |
| 5 | **Infrastructure** (MySQL, storage, queue, mail) | persistence and external services | is reached except through the layers above |

> A rule exists in **exactly one place** (its Action). If you find the same rule in two places, one is
> wrong. See [`reference/guide-figures.md`](reference/guide-figures.md) Figure 2.1.

### The four shared foundations (built once in Phase 0, used by every module)

| Foundation | What it gives every feature |
|------------|-----------------------------|
| **Auth & RBAC** | Login, route‑level permissions; **one role per user**, with an **`is_agent` flag** that controls who can be assigned visits |
| **Audit & No‑Delete** | An **append‑only** activity log, plus **cancel‑and‑duplicate** correction and record **versioning**, applied through base‑model traits |
| **Dynamic Settings** | One reusable list structure that powers **every dropdown** (payment methods, floors, areas, sources, …) |
| **Media & Files** | Project‑ and unit‑level photos, video, PDF and PPTX in object storage with a CDN, **versioned and never deleted** |

---

## 3. The ten‑step write lifecycle

Every write follows the same ten steps. Learn it once; every feature you build (or ask AI to build)
looks the same. Example: an agent reserves a unit.

```
1  Vue          click "Reserve"  →  axios POST /api/v1/units/{id}/reserve
2  Route        /api/v1/units/{id}/reserve
3  Middleware   authenticated?  has permission?  (auth:sanctum + can:units.reserve)
4  FormRequest  validate input; reject if invalid
5  Controller   thin — just calls the Action
6  Action       ReserveUnit: business rules + 48h hold           ← business logic lives HERE
7  Model        Unit status → "Reserved"  (new version, not an overwrite)
8  MySQL        change saved inside a transaction
9  API Resource shape clean JSON for the frontend
10 Vue updates  Pinia store + UI — unit shows "Reserved"

        ↳ Audit log written automatically: who · what · when · before → after · IP  (append‑only, never edited)
```

**Why it pays off:** each layer has one responsibility, so code is easy to find, test and change;
business rules sit in Actions, so a rule cannot be duplicated or contradicted; the audit entry and
"new version instead of overwrite" happen at the model layer, so traceability is automatic;
`delete()` never reaches the database — it becomes a `cancelled` status, keeping the no‑hide /
no‑remove guarantee intact.

---

## 4. Naming conventions

Consistency removes a thousand small decisions. Use these **everywhere**.

| Thing | Rule | Examples |
|-------|------|----------|
| Models | singular PascalCase | `Unit`, `ClientProject`, `Versement` |
| Actions | a verb phrase describing the job | `ReserveUnit`, `GenerateVersementDocument`, `MatchDesireToInventory` |
| Controllers | resource name + `Controller`, thin, delegates to Actions | `UnitController` |
| FormRequests | verb/resource + `Request` | `ReserveUnitRequest`, `StoreClientRequest` |
| API Resources | model + `Resource` | `UnitResource`, `ClientResource` |
| DB tables | plural snake_case | `units`, `client_projects`, `versements` |
| Foreign keys | `singular_id` | `location_id`, `client_id`, `role_id` |
| API routes | versioned + plural | `/api/v1/units`, `/api/v1/clients` |
| Vue components | PascalCase `.vue`, base components prefixed `Base` | `UnitCard.vue`, `BaseButton.vue` |
| Pinia stores | `useXStore`, feature‑scoped | `useInventoryStore` |
| Enums (PHP) | PascalCase, singular | `UnitStatus`, `VisitType` |

---

## 5. The modular structure (mirrored back‑to‑front)

The backend groups code by **feature module**; the frontend mirrors it with **feature folders** of the
same name. Adding a feature is adding a folder, not scattering changes across the app.

```
Backend  app/Modules/{Settings,Inventory,Clients,Pipeline,Payments,Collaboration,Analytics}
         each: Models/  Actions/  Http/{Controllers,Requests,Resources}/  routes.php
         plus  app/Core/ (BaseModel, traits, audit, RBAC)  and  app/Providers/

Frontend src/features/{inventory,clients,pipeline,payments,...}
         each: views/  components/  store.js  api.js
         plus  components/base/  composables/  layouts/  router/  assets/styles/
```

Backend module ↔ frontend feature mapping (note: the guide splits Pipeline out on the backend; on the
frontend the pipeline UI lives under `features/pipeline/`):

| Backend module | Frontend feature | Domain |
|----------------|------------------|--------|
| `Settings` | `settings` | roles, departments, users, dynamic lists |
| `Inventory` | `inventory` | locations, units, boxes, media |
| `Clients` | `clients` | clients, desire |
| `Pipeline` | `pipeline` | calls, visits, tasks, next‑action |
| `Payments` | `payments` | versements, schedules, documents |
| `Collaboration` | `collaboration` | chat, notifications |
| `Analytics` | `analytics` | dashboards, audit views |

---

## 6. Definition of Done (the merge gate)

A feature is **done** only when *all* of these are true. Treat the list as a gate before merging.

- [ ] **Works end to end** — UI, API and database, on phone and desktop, in **both themes**.
- [ ] **Respects the foundations** — permissions checked, Actions used for logic, nothing deletes, every action logged.
- [ ] **Tested** — at least one feature test for the happy path *and* the key rule (e.g. the 48‑hour expiry).
- [ ] **Formatted and reviewed** — Pint/ESLint clean, approved in a pull request.

See [`conventions/git-workflow.md`](conventions/git-workflow.md) for the full workflow and
[`testing-and-going-live.md`](testing-and-going-live.md) for the test strategy.

---

## 7. Confirmed stack versions

| Component | Version / choice | Notes |
|-----------|------------------|-------|
| PHP | **8.3** | inside the `app` container (php‑fpm) |
| Laravel | **13.x** (current stable) | API only; Sanctum SPA cookie auth |
| MySQL | **8.x** (SQLite for tests) | persistent named volume `db-data`; tests run on in‑memory SQLite |
| Node | **20** | Vite dev server + build |
| Vue | **3.5** (Composition API, `<script setup>`) | Pinia + Vue Router |
| Vite | **8.x** | dev server + build |
| Tailwind CSS | **3.x** | wired to CSS design tokens |
| Tests | **PHPUnit** (backend) · **Vitest** (frontend) | Pest is an allowed alternative; CI uses `php artisan test` |
| Lint/format | **Pint** (PHP) · **ESLint 8 + eslint‑plugin‑vue 9 + Prettier** (JS/Vue) | configured in‑repo |
| Redis | alpine | cache, queues, sessions |
| Web server | Nginx (alpine) | reverse proxy on `:8080` |
| Mail (dev) | Mailpit | catches dev e‑mails on `:8025` |

> These are the versions the Phase 0 build was verified against (see the repo's `backend/composer.json`
> and `frontend/package.json`). Laravel resolved to **13.x** as the current stable at build time.

---

**Next:** [`phase-0-foundations/00-prerequisites-ubuntu.md`](phase-0-foundations/00-prerequisites-ubuntu.md)
