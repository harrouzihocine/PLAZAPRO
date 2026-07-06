# Full-Stack Audit — PLAZA PRO (Laravel + Vue 3 + MySQL + Redis + Docker + Nginx)

## Context

You are auditing **PLAZA PRO**, a production real estate sales CRM built for a property developer. It manages the sale of apartments, commercial locals, and parking boxes, with roughly 15–50+ concurrent users.

- **Backend:** Laravel REST API (decoupled architecture)
- **Frontend:** Vue.js 3 SPA
- **Database:** MySQL — **Cache/queues:** Redis
- **Deployment:** Docker Compose behind an Nginx reverse proxy, self-hosted on Ubuntu
- **External access:** Cloudflare will be added later for public HTTPS access
- **Auth:** [Sanctum / JWT — specify which one you use]

Business-critical invariants this audit must protect:

1. **Zero-deletion model** — records are never hard-deleted; corrections happen via cancel-and-duplicate, backed by an append-only audit log.
2. **Reservation hold engine** — units on the visual stacking plan can be held/reserved; double-booking the same unit is unacceptable.
3. **Versements (instalment payments)** — real financial data; amounts, schedules, and generated documents must be exact and traceable.
4. **Role-based access with agent flag** — an agent must never see or act on another agent's clients, leads, or deals unless their role explicitly allows it.

## Mission

Perform a complete, evidence-based audit of the entire codebase and infrastructure — security, performance, database design, business-logic integrity, code quality, bugs, and deployment configuration — then deliver a prioritized report.

## Ground rules

1. **Audit only — do not modify any code.** We will fix issues together after I review the report.
2. Every finding must cite the exact **file path + line number** and quote the offending code or config. Generic advice without a code reference does not count as a finding.
3. If an area is clean, say so in one line. Do not pad the report with theoretical or invented issues.
4. Any weakness affecting **financial data integrity, the audit trail, reservation concurrency, or client PII** is **Critical** severity by default.
5. If a business rule is ambiguous (payment schedules, hold durations, pipeline rules), ask me — never assume.
6. Work module by module and keep running notes so nothing is skipped.

## Phase 0 — Reconnaissance (do this before judging anything)

- Read `composer.json`, `package.json`, `.env.example`, `config/`, `docker-compose.yml`, all Dockerfiles, and the Nginx config — note Laravel/PHP/Vue versions, key packages, and container topology.
- List all routes (`routes/api.php`, `routes/web.php`) grouped by module (projects, units, clients, reservations, payments, documents, media, dashboard).
- Inventory the backend: Models + relationships, Controllers, Services/Actions, Form Requests, API Resources, Policies, Middleware, Jobs, Scheduled tasks, Migrations.
- Inventory the frontend: folder structure, router, state management (Pinia/Vuex), API layer (axios setup), main views/components per module.
- Output a short architecture map (1 page max) before starting Phase 1.

## Phase 1 — Security (highest priority)

**Authentication & authorization**
- Is every API route protected by the correct middleware? List every unprotected route.
- Are Policies/Gates enforced on every resource? Test for **IDOR**: can agent A read or modify agent B's client, lead, reservation, or payment by changing an ID in the URL or payload? Can a low-privilege user change a unit's price or status?
- Is the agent flag and role model enforced **server-side**, not just hidden in the Vue UI?
- Token handling: where the token lives on the frontend (localStorage vs httpOnly cookie), expiration, logout/revocation.

**Input & injection**
- Is every write endpoint backed by a Form Request? Flag inline or missing validation.
- Mass assignment: check `$fillable` / `$guarded` on every model; flag patterns like `Model::create($request->all())` — especially on units, payments, and audit-related tables.
- SQL injection: audit every `DB::raw`, `whereRaw`, `selectRaw`, and raw statement that touches user input (search/filter endpoints are prime suspects).
- XSS: every `v-html` in Vue components, unescaped output, rendering of user-entered notes/descriptions.
- Media uploads (photos, video, PDF, PPTX): MIME/extension validation with server-side detection (not just the client), size limits, filename sanitization, and **storage location** — files must not be publicly guessable; delivery must go through an authorization check or signed URLs.

**Data exposure**
- Are API responses serialized through API Resources, or do endpoints return full models leaking hidden fields (client phone numbers, payment details, internal notes, other agents' data)?
- Sensitive data in logs, in error responses (`APP_DEBUG` must be off in production), or committed to git (`.env` in history?).
- Run `composer audit` and `npm audit`; list vulnerable dependencies with severity.

**Platform**
- Rate limiting on login and expensive endpoints, CORS configuration, security headers, session/cookie config, password hashing and rules.

## Phase 2 — Performance & N+1 queries

- **N+1:** inspect every controller/service/Resource returning collections. Flag any relationship accessed in a loop or inside an API Resource without eager loading (`with()`, `load()`, `withCount()`). Recommend enabling `Model::preventLazyLoading(! app()->isProduction())` in `AppServiceProvider`.
- **Stacking plan endpoint:** it renders potentially hundreds of units with statuses and holds — verify it resolves in a small constant number of queries and returns a lean payload (no full media objects per unit).
- Missing pagination on client lists, pipeline views, payment lists; endpoints returning entire tables.
- **Indexes:** compare migrations against real query patterns — foreign keys plus hot columns (`units.status`, `units.project_id`, client phone/name search fields, payment due dates, reservation expiry).
- Heavy synchronous work that belongs in queued Jobs: branded document/PDF generation, alert dispatch (speed-to-lead, deal-rot), media processing/thumbnails.
- Redis usage: is caching actually applied where it pays off (reference data, dashboard aggregates), and are queues/sessions correctly on Redis?
- Frontend: duplicate API calls on mount, missing lazy loading of routes/components, full-size images where thumbnails should load, requests without loading/error states.

## Phase 3 — Database design & business integrity

- Money stored as `DECIMAL` — never `FLOAT`/`DOUBLE`. Applies to unit prices, versement amounts, totals, discounts.
- Foreign key constraints present and correct; orphaned-record risks.
- **Transactions:** every multi-step financial operation (reservation + status change, payment + schedule update + receipt, cancel-and-duplicate) must be wrapped in `DB::transaction()`. Flag any that are not.
- **Reservation concurrency:** two agents reserving the same unit at the same moment — is there `lockForUpdate()`, a unique constraint, or an atomic status transition preventing double-booking? Same question for hold expiry: can the expiry job release a unit at the exact moment someone converts the hold?
- **Zero-deletion verification:** search the entire codebase for `delete()`, `destroy()`, `forceDelete()`, `truncate`, and raw `DELETE` statements. Any hard delete on business data is a Critical finding.
- **Audit log integrity:** confirm the audit table is genuinely append-only — no update/delete code paths touch it, and every state-changing action writes to it with user ID, timestamp, and before/after values.
- Cancel-and-duplicate integrity: does the duplicate correctly reference the cancelled original, and do totals/schedules stay consistent?

## Phase 4 — Code quality & architecture

**Backend**
- Fat controllers: business logic that belongs in Service/Action classes. List the worst offenders with line counts.
- Long methods (> ~50 lines) and god classes; duplicated logic across controllers; dead code.
- Magic numbers/strings (unit statuses, pipeline stages, roles) → enums or config, not scattered literals.
- Consistent API response format and centralized exception handling.
- Naming and structure vs Laravel conventions (PSR-12, resource controllers, route naming, RESTful design).

**Frontend (Vue 3)**
- Oversized components (> ~300 lines) mixing data-fetching + logic + presentation — propose splits into composables and child components (the stacking plan and pipeline views are likely candidates).
- Consistency: Options API and Composition API mixed? Props without types/validation? Undeclared emits?
- Centralized API layer: one axios instance with interceptors (auth token, 401 handling, error notifications) vs scattered ad-hoc calls.
- State: correct use of Pinia/Vuex vs prop drilling vs duplicated local state (unit statuses shown in multiple views must come from one source of truth).
- Duplicated form-validation logic; missing disabled/loading state on submit buttons (double-submit risk on reservation and payment forms).

## Phase 5 — Bugs & logic risks

- Floating-point arithmetic on money (totals, instalment splits, discounts, rounding) — must use decimal-safe math; verify instalment schedules sum exactly to the unit price.
- Null/empty edge cases, unchecked array access, unhandled promise rejections in Vue.
- Empty `catch` blocks or silently swallowed errors; missing HTTP error handling.
- Timezone and date-handling inconsistencies (payment due dates, hold expiry times) between server, MySQL, and client.
- Pipeline/state-machine gaps: can a unit or deal reach an invalid state through an unguarded transition?

## Phase 6 — Infrastructure: Docker, Nginx, Redis, and Cloudflare readiness

**Docker Compose**
- Image versions pinned (no `:latest`); containers not running as root where avoidable.
- Secrets: passwords via env files only — never baked into images or committed; `.env` excluded from build context.
- **Port exposure:** MySQL and Redis must NOT publish ports to the host — internal Docker network only. Only Nginx should expose 80/443.
- Named volume for MySQL data + a real backup strategy (dump schedule, tested restore).
- Queue worker and scheduler containers actually defined and running (with restart policies and healthchecks) — hold expiry, speed-to-lead, and deal-rot alerts silently die without them.
- Log rotation so containers don't fill the disk.

**Nginx reverse proxy**
- `X-Forwarded-For` / `X-Forwarded-Proto` / `Host` headers passed, and Laravel's `TrustProxies` middleware configured to match — otherwise rate limiting and the audit log record the proxy's IP instead of the real user's.
- `client_max_body_size` and timeouts sized for video/PPTX uploads.
- Security headers (`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`; CSP if feasible), gzip, static asset caching.
- Rate limiting on `/login`; deny access to `.env`, `.git`, and storage internals.

**Redis**
- `requirepass` set; correct persistence settings so queued jobs survive a restart; separate DBs or prefixes for cache vs queue vs session.

**Cloudflare readiness (for later public HTTPS)**
- Plan for SSL mode **Full (strict)** with a Cloudflare Origin Certificate installed on Nginx — never Flexible mode (Flexible leaves origin traffic unencrypted).
- Add Cloudflare's published IP ranges to Laravel's trusted proxies and read `CF-Connecting-IP`, so real visitor IPs reach rate limiting and the audit log.
- `APP_URL` on https, `SESSION_SECURE_COOKIE=true`, and a mixed-content check across the SPA.
- Firewall plan: once behind Cloudflare, accept 80/443 only from Cloudflare IP ranges — or evaluate **Cloudflare Tunnel** instead, which needs no open ports and hides the origin IP entirely (a strong fit for a self-hosted office server).
- Cloudflare's free plan caps request bodies at **100 MB** — flag any upload flow (especially video) that could exceed it and recommend chunked uploads if needed.
- If real-time features use WebSockets, verify they will proxy correctly through both Nginx and Cloudflare.

## Deliverable — write `AUDIT_REPORT.md`

1. **Executive summary** — a health score per area (Security / Performance / Database & integrity / Code quality / Frontend / Infrastructure) and the top 10 issues.
2. **Findings grouped by severity** — 🔴 Critical, 🟠 High, 🟡 Medium, 🟢 Low. Each finding: ID, file:line, code excerpt, why it matters, concrete fix with a code snippet, estimated effort (S/M/L).
3. **Quick wins** — high-impact fixes that take under 30 minutes each.
4. **Remediation roadmap** — the exact order in which we should fix things, plus a short pre-Cloudflare go-live checklist.

After delivering the report, stop and wait for my instructions. We will then fix Critical issues first, one at a time, reviewing each fix before moving to the next.
