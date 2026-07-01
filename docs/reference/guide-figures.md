# Reference — The Guide's Figures (transcribed)

The [Developer Build Guide](PLAZA_PRO_Developer_Build_Guide.docx) contains five diagrams that carry
spec detail beyond the prose. They are transcribed here so the information is searchable and versioned
alongside the playbook. The original images are embedded in the `.docx`.

---

## Figure 2.1 — Application Architecture (layered + modular)

*"Clear layers keep the code organised; feature modules keep it extensible."*

**The layers (a request flows top to bottom):**
1. **Presentation — Vue 3 SPA** — components, views, Pinia stores, router · talks to the API over HTTPS · responsive & themed
2. **API — Routes, Controllers, Requests, Resources** — thin controllers · FormRequest validation · API Resources shape the JSON · versioned `/api/v1`
3. **Application — Services & Actions** — one job per Action (`ReserveUnit`, `RecordVersement`) · business rules live here, not in controllers
4. **Domain — Models & Modules** — Eloquent models, enums, relationships · grouped by feature module · the meaning of the system
5. **Infrastructure — MySQL · Storage · Queue · Mail** — migrations, persistence, file/media storage, background jobs · accessed only through the layers above

**Cross‑cutting foundations (built once, used by every module):**
- **Auth & RBAC** — route‑level permissions · one role per user · agent flag controls visit assignment
- **Audit & No‑Delete** — append‑only activity log · cancel + duplicate · versioned records · base traits on every model
- **Dynamic Settings** — one reusable list structure backs every dropdown (payment methods, floors, locations, sources…)
- **Media & Files** — object storage + CDN · project & unit media · versioned, never deleted

**Feature modules (add one without touching the others):** Settings · Inventory · Clients · Pipeline ·
Payments · Collaboration · Analytics · *(+ future module drops in cleanly)*.

---

## Figure 3.1 — Development Environment · Container Topology

*"One command starts every service; each runs in its own container on a shared Docker network."*

**Ubuntu host · Docker Engine · network `plaza-net`:**
- **nginx** — web server / reverse proxy · routes `/ → app`, `/api → app` · `localhost:8080`
- **app (php‑fpm)** — Laravel API + business logic · **PHP 8.3 · Composer · Artisan** · the heart of the backend
- **node (vite)** — Vue 3 dev server + HMR · hot reload while you code · `localhost:5173`
- **mysql** — database (persistent) · MySQL 8 · stores all data · volume `db-data`
- **redis** — cache · queues · sessions · speeds up the app, runs background jobs
- **queue worker** — async tasks (same image as app) · emails, document generation, notifications
- **mailpit** — catches dev e‑mails · `localhost:8025` · test notifications without sending real mail

**Bind mount:** source folder mounted into `app` & `node` — edit locally, runs instantly inside.
**Named volumes:** DB files and uploaded media persist on the host, surviving container restarts.
Browser opens `localhost:8080 → the app`.

> The **queue worker** container is why the playbook's `docker-compose.yml`
> ([`../phase-0-foundations/02-docker-stack.md`](../phase-0-foundations/02-docker-stack.md)) adds a
> `queue` service beyond the guide's minimal compose snippet.

---

## Figure 4.1 — How One Request Flows (and leaves a trail)

*Example: an agent reserves a unit. Every layer has one clear job; the action is logged automatically.*

1. **Vue** — click "Reserve" → `axios POST /api/v1/units/{id}/reserve`
2. **Route** — `/api/v1/units/{id}/reserve`
3. **Middleware** — authenticated? has permission?
4. **FormRequest** — validate input; reject if invalid
5. **Controller** — thin — just calls the Action
6. **Action** — `ReserveUnit`: rules + 48h hold · **business logic lives here**
7. **Model** — Unit status → "Reserved" · **new version, not overwrite**
8. **MySQL** — change saved **inside a transaction**
9. **API Resource** — shape clean JSON for the frontend
10. **Vue updates** — Pinia store + UI · unit shows "Reserved"

↳ **Audit log written automatically:** who · what · when · before → after · IP — **append‑only, never edited.**

**Why this structure pays off:** one responsibility per layer; business rules in Actions (can't be
duplicated/contradicted); audit + "new version instead of overwrite" at the model layer (traceability
automatic); "delete" never reaches the DB — it becomes a `cancelled` status.
*The same ten steps describe every write in the system — learn it once, apply it to every feature.*

---

## Figure 5.1 — One design system, two themes (CSS variables)

Gold/white (day) and gold/black (night) driven by CSS variables (design tokens); the **gold accent is
identical** in both modes; a one‑line `data-theme` toggle swaps the palette and every component
re‑themes instantly. Transcribed as code in
[`../phase-0-foundations/09-theming-and-appshell.md`](../phase-0-foundations/09-theming-and-appshell.md).

---

## Figure 9.1 — The Build Sequence (foundations first, then feature by feature)

**Every feature = one vertical slice:** UI (Vue component + store, responsive/themed) → API (route →
action → resource, validation + business rules) → DB (migration + model, versioned/audit‑ready) → Test
(feature + unit, prove it works before moving on). *Applied to each phase.*

**The phases, in order:**
- **0 · Foundations** — containers · auth · RBAC · base model + audit + no‑delete · theming · CI
- **1 · Settings backbone** — roles, departments, users, dynamic lists, ratings, reasons
- **2 · Inventory & media** — locations, units, boxes, photo/video/PDF/PPTX, stacking plan, reservation hold
- **3 · Clients & pipeline** — clients, desire, calls/office/apartment visits, enforced next action, reminders
- **4 · Payments & documents** — versements, schedules, cancel + duplicate, branded PDF generation
- **5 · Collaboration** — notifications, tasks page, chat (text/image/voice), visibility & sharing
- **6 · Analytics & audit views** — role dashboards, source ROI, unit intelligence, admin audit feed
- **7 · Hardening & launch** — mobile polish, performance, security pass, backups, deploy

**Two rules that keep momentum:** (1) Finish Phase 0 before anything else. (2) Ship one vertical slice
at a time — a small feature that works from screen to database — instead of building whole layers in
isolation. *Result: the app is demoable at the end of every phase, and the structure never drifts.*
