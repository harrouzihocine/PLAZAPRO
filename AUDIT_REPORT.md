# PLAZA PRO — Full-Stack Audit Report

**Date:** 2026-07-06
**Scope:** Laravel 13 API + Vue 3 SPA + MySQL 8 + Redis + Docker/Nginx, self-hosted, Cloudflare Tunnel front door.
**Method:** Evidence-based, module by module. Every finding cites `file:line` and quotes the code. Clean areas are stated in one line. No fixes were applied — this is audit-only.
**Auth resolved:** the brief left `[Sanctum / JWT]` open — the app uses **Laravel Sanctum in SPA cookie/session mode** (plus a stateless Bearer-token path for a future mobile client).

---

## 1. Executive summary

This is a **well-architected, security-conscious codebase** that is far above the norm for a self-hosted line-of-business app. The core business invariants the audit was asked to protect are, for the most part, genuinely enforced in code — the zero-deletion model and the audit trail are exemplary, money is decimal-and-bcmath throughout, and the production infrastructure (network isolation, secrets, headers, backups) is close to textbook. The issues that remain are concentrated in **reservation/sale concurrency** and a **handful of authorization and double-submit gaps** — real, but fixable without structural change.

### Health scores

| Area | Score | One-line justification |
|---|---|---|
| **Security (authz/authn)** | **B** | RBAC + visibility scopes are strong and consistent; a few write endpoints skip the visibility check the read paths enforce, and deactivating a user doesn't end their session. |
| **Performance & N+1** | **A−** | `preventLazyLoading` on in dev, eager-loading disciplined, stacking plan is lean and constant-query; main gap is missing pagination on list endpoints. |
| **Database & integrity** | **A−** | Money is `decimal(12,2)` + bcmath; zero-deletion and append-only audit log are enforced at the base-model level; the one real weakness is unlocked check-then-act on the sale/close path. |
| **Code quality (backend)** | **A** | Thin controllers, Actions pattern, enums over magic strings, consistent Resource responses. |
| **Code quality (frontend)** | **B+** | Central axios layer, fully lazy-loaded router, Pinia; a few 500–800 line components and inconsistent submit-guarding. |
| **Infrastructure** | **A−** | Prod compose + nginx are excellent (no exposed DB/Redis, CSP, HSTS, rate limits, backups); floating image tags and the Cloudflare 100 MB body cap are the open items. |

### Top 10 issues (ranked)

| # | ID | Sev | Summary |
|---|---|---|---|
| 1 | **DB-1** | 🔴 Critical | Deal-close / win path does check-then-act on the unit with no `lockForUpdate` and no "already sold" guard → concurrent closes can double-sell one unit. |
| 2 | **SEC-1** | 🟠 High | Opening a deal and re-syncing its boxes never checks project **visibility** — an agent can act on (and place holds on units of) a project they can't see. |
| 3 | **SEC-2** | 🟠 High | `ReleaseReservation` / `ConvertReservation` / the hold-expiry sweeper restore unit status unconditionally, ignoring backups, On-Hold deposit locks, and no-expiry deal holds. |
| 4 | **SEC-3** | 🟠 High | Deactivating or cancelling a user does not revoke their live session or API tokens — a fired agent keeps working. |
| 5 | **FE-1** | 🟠 High | Payment/deposit submit buttons lack a submit guard → a double-click records duplicate versements (financial data). |
| 6 | **PERF-1** | 🟡 Medium | No pagination on clients / units / deals / versements list endpoints — unbounded `->get()`. |
| 7 | **INFRA-2** | 🟡 Medium | Uploads allow 200 MB but Cloudflare's Free plan rejects request bodies > 100 MB at the edge — video uploads via the tunnel will 413. |
| 8 | **INFRA-1** | 🟢 Low | Production images use floating tags (`cloudflared:latest`, `nginx:alpine`, `redis:alpine`). |
| 9 | **SEC-4** | 🟢 Low | Sanctum personal-access tokens never expire (`expiration => null`). |
| 10 | **QUAL-1** | 🟢 Low | Five Vue components exceed 500 lines (`DealPanel.vue` 832) — split candidates. |

---

## 2. Findings by severity

### 🔴 Critical

#### DB-1 — Deal close/win is a check-then-act on the unit with no row lock or "sold" guard (double-sale)
**File:** [backend/app/Modules/Clients/Actions/CloseDealUnit.php:53-65](backend/app/Modules/Clients/Actions/CloseDealUnit.php#L53-L65), [:103-132](backend/app/Modules/Clients/Actions/CloseDealUnit.php#L103-L132)

The open-deal guard runs **before** the transaction, and `win()` never locks the unit row nor asserts it isn't already sold:

```php
// line 53 — read OUTSIDE the transaction
abort_unless($deal->isActive() && ! $deal->state->isClosed(), 422, 'This deal is not open.');
...
$deal = DB::transaction(function () use (...) {   // line 57
    $outcome === 'won' ? $this->win($item, ...) : $this->lose($item);
```
```php
private function win(DealItem $item, ...): void {           // line 103
    $item->load(['unit', ...]);                              // plain SELECT, no lock
    $unit = $item->unit;
    abort_if($unit->sale_status === SaleStatus::OnHold && ..., 422, ...); // only OnHold-for-another
    // ↑ NO abort_if($unit->sale_status === Sold), NO Unit::...->lockForUpdate()
    $unit->update(['sale_status' => SaleStatus::Sold->value, ...]);       // line 128
```

**Why it matters.** A unit can be reserved by **several** projects at once (documented multi-project backups). Two agents closing two different deals on the **same** unit at the same moment both pass the pre-transaction guard and both run `win()`: each marks its own apartment `won` and sets the unit `sold`. `releaseBackups()` only demotes items still in `reserved` state, so the second transaction's item — already `won` in its own tx — is not released. Result: **one physical unit sold twice**, two `UnitSold` broadcasts, two agreed prices, two payment tracks. This is the exact reservation-concurrency failure the brief classifies as Critical.

**Fix (reuse the pattern already in `ReserveUnit`).**
```php
$deal = DB::transaction(function () use (...) {
    $unit = Unit::whereKey($item->unit_id)->lockForUpdate()->firstOrFail();
    abort_if($unit->sale_status === SaleStatus::Sold, 422, 'This unit is already sold.');
    abort_if($unit->sale_status === SaleStatus::OnHold
        && (int) $unit->onhold_project_id !== (int) $item->deal->client_project_id,
        422, 'This unit is on hold for another client.');
    // re-assert the deal/item are still open now that we hold the lock
    ...
});
```
`ReserveUnit::handle` ([ReserveUnit.php:29-33](backend/app/Modules/Inventory/Actions/ReserveUnit.php#L29-L33)) already does `lockForUpdate()` + status re-check inside the transaction — this is the same fix applied to the close path (and to SEC-2 below). **Effort: M.**

---

### 🟠 High

#### SEC-1 — Opening a deal / syncing deal boxes never checks project visibility
**Files:** [backend/app/Modules/Clients/Http/Requests/StoreDealRequest.php:17-22](backend/app/Modules/Clients/Http/Requests/StoreDealRequest.php#L17-L22), [backend/app/Modules/Clients/Actions/CreateDeal.php:54-83](backend/app/Modules/Clients/Actions/CreateDeal.php#L54-L83), [backend/app/Modules/Clients/Http/Requests/SyncDealBoxesRequest.php:16-18](backend/app/Modules/Clients/Http/Requests/SyncDealBoxesRequest.php#L16-L18)

`StoreDealRequest` authorizes on **permission only**:
```php
return $user !== null && ($user->can('visits.conduct') || $user->can('deals.direct'));
```
`CreateDeal` then checks deal *provenance* (the visit/call belongs to the project) but **never** `$project->isVisibleTo($actor)`. The route model-binds `{project}` straight from the URL. So an agent with `visits.conduct` can `POST /projects/{anyId}/deals` against a project they cannot see, supplying a `visit_id` that belongs to that project (integer IDs are guessable) — creating deal rows and, via `ReserveUnit`, placing **no-expiry holds** on that project's units. `syncUnitBoxes` has the same shape: `SyncDealBoxesRequest` checks only `can('visits.conduct')`, and the controller only checks `item->deal_id === deal->id` ([DealController.php:154-165](backend/app/Modules/Clients/Http/Controllers/DealController.php#L154-L165)).

**Why it matters.** Agent isolation is a core invariant ("an agent must never see or act on another agent's clients/deals unless their role allows it"). The read endpoints get this right — `DealController::index/participants` call `abort_unless($project->isVisibleTo($request->user()), 404)`. The write paths regress from that standard.

**The convention to copy already exists** — `ProposeInSiteVisitRequest` does exactly this:
```php
// ProposeInSiteVisitRequest.php:22-30
$project = $this->route('project');
return $project instanceof ClientProject && $project->isVisibleTo($user);
```
Apply the same `isVisibleTo` check in `StoreDealRequest::authorize()` and `SyncDealBoxesRequest::authorize()` (and, for defense in depth, assert it inside `CreateDeal::handle`). **Effort: S.**

#### SEC-2 — Reservation release/convert/expiry restore unit status unconditionally
**Files:** [backend/app/Modules/Inventory/Actions/ReleaseReservation.php:18-28](backend/app/Modules/Inventory/Actions/ReleaseReservation.php#L18-L28), [ConvertReservation.php:20-39](backend/app/Modules/Inventory/Actions/ConvertReservation.php#L20-L39), [ExpireReservationHolds.php:27-38](backend/app/Modules/Inventory/Actions/ExpireReservationHolds.php#L27-L38)

`ReleaseReservation` flips the unit straight to `Available`, ignoring every other state:
```php
abort_if($reservation->hold_status !== HoldStatus::Active, 422, ...); // guard OUTSIDE tx
return DB::transaction(function () use ($reservation) {
    $reservation->update(['hold_status' => HoldStatus::Released->value]);
    $reservation->unit->update(['sale_status' => SaleStatus::Available->value]); // unconditional
```
Three problems, all on financially-meaningful state:
1. **Backups erased.** If other projects still hold the unit, it should return to `Reserved`, not `Available`. The correct helper — `Unit::revertToMarket()` ([Unit.php:131-144](backend/app/Modules/Inventory/Models/Unit.php#L131-L144)) — already exists and picks `Reserved`/`Available` based on `hasActiveHold()`.
2. **On-Hold deposit lock lifted.** A unit on hold for another project (a paid holding deposit) would be knocked back to `Available`. `CloseDealUnit::lose()` guards this correctly ([:234-239](backend/app/Modules/Clients/Actions/CloseDealUnit.php#L234-L239)); `ReleaseReservation` does not.
3. **No-expiry deal holds releasable out of band.** `release` accepts any active hold, including a no-expiry hold backing an open deal — contradicting the documented rule that "only closing the deal releases or converts it" ([ReserveUnit.php:22-23](backend/app/Modules/Inventory/Actions/ReserveUnit.php#L22-L23)).

`ConvertReservation` similarly converts to `Sold` without a unit `lockForUpdate`, without releasing backups, and without the On-Hold-holder check. `ExpireReservationHolds` flips `Reserved → Available` after only checking the unit is *currently* `Reserved` — it doesn't re-check `hasActiveHold()`, so a unit still held by a backup can be marked `Available` while a live hold exists.

**Fix.** Route all three through `revertToMarket()`/`lockForUpdate` and refuse releasing a no-expiry deal hold outside the deal flow. **Effort: M.**

#### SEC-3 — Deactivating or cancelling a user does not revoke live sessions or tokens
**Files:** [backend/app/Modules/Settings/Actions/SetUserActive.php:31](backend/app/Modules/Settings/Actions/SetUserActive.php#L31), [CancelUser.php:14-32](backend/app/Modules/Settings/Actions/CancelUser.php#L14-L32)

`SetUserActive` sets `is_active = false`; `CancelUser` sets `status = cancelled`. Neither touches sessions or Sanctum tokens. There is **no per-request `is_active`/`status` guard** anywhere in `app/Http` (grep is empty), the `User` model has **no global scope** excluding inactive/cancelled users, and `sanctum.expiration` is `null`. `is_active` is only checked at **login** ([AuthController.php:44,80](backend/app/Modules/Settings/Http/Controllers/AuthController.php#L44)). So a user who is deactivated or cancelled while logged in keeps full access until their session TTL (120 min) — or **indefinitely** with a mobile personal-access token.

**Why it matters.** For a CRM whose whole premise is per-agent data isolation, a fired agent retaining live access is a direct breach of the access-control invariant.

**Fix.** In both actions, `$user->tokens()->delete()` and invalidate their sessions; and/or add an `EnsureUserActive` middleware to the `auth:sanctum` group that 401s an inactive/cancelled user (plus Laravel's `AuthenticateSession` for immediate session logout). **Effort: S–M.**

#### FE-1 — Payment/deposit submit buttons have no double-submit guard
**Files:** [frontend/src/features/payments/components/PaymentsPanel.vue:106-122](frontend/src/features/payments/components/PaymentsPanel.vue#L106-L122) (button [:332](frontend/src/features/payments/components/PaymentsPanel.vue#L332)), [frontend/src/features/clients/components/DealPanel.vue:63-84](frontend/src/features/clients/components/DealPanel.vue#L63-L84) (button [:626](frontend/src/features/clients/components/DealPanel.vue#L626))

`recordPayment()`, `saveSchedule()` and `submitCorrect()` set no in-flight flag, and their buttons carry no `:loading`/`:disabled`:
```html
<Button label="Record" icon="pi pi-check" size="small" @click="recordPayment" />
```
In `DealPanel`, the deposit button *looks* guarded (`:loading="store.saving"`) but `submitDeposit()` calls `versementsApi.record()` **directly** and never sets `store.saving`, so the guard never engages. `RecordVersement` is not idempotent — it inserts a row per call — so a double-click books **two versements** (and, on a first deposit, arms the On-Hold timer redundantly).

**Why it matters.** These are real financial writes. The brief treats financial-data integrity as Critical; I've placed this at **High** because every duplicate is fully audited and correctable via the existing cancel/refund flow (it's a data-quality/UX defect, not silent corruption) — but it should be fixed before go-live. Note the *close/win/lose* flows in the same panel **do** guard correctly via `store.saving`, so the pattern is one line away.

**Fix.** Add a local `submitting` ref (or route these through a store action that toggles `saving`) and bind `:loading`/`:disabled` on every financial submit button. **Effort: S.**

---

### 🟡 Medium

#### PERF-1 — No pagination on list endpoints
**Files:** [backend/app/Modules/Clients/Http/Controllers/ClientController.php:31-59](backend/app/Modules/Clients/Http/Controllers/ClientController.php#L31-L59), also `DealController::index`, `VersementController::index`, `UnitController::index`, `LocationController::index`, `TeamLogs`.

Only 4 controllers paginate (audit, per-record activity, oversight, notifications). `ClientController::index` ends in `->get()` with no limit; the clients and units tables grow unbounded, so each list load serializes the entire (visible) table. Server-side search/filter is present and mitigates day-to-day use, but the endpoint is still unbounded.

**Fix.** `->paginate(50)` on clients and units at minimum; the frontend already has the axios layer to pass `?page`. **Effort: S–M.**

#### INFRA-2 — 200 MB uploads vs Cloudflare Free's 100 MB body cap
**Files:** [backend/app/Modules/Inventory/Http/Requests/UploadMediaRequest.php:27](backend/app/Modules/Inventory/Http/Requests/UploadMediaRequest.php#L27) (`max: 200*1024`), [docker/php/uploads.ini:12-13](docker/php/uploads.ini#L12-L13), [docker/nginx/prod.conf:39](docker/nginx/prod.conf#L39) (`client_max_body_size 210M`)

The whole origin stack is sized for 200 MB media, but public traffic arrives through the Cloudflare Tunnel and **Cloudflare's Free plan hard-caps request bodies at 100 MB** — an edge `413` before the request ever reaches nginx. Any video between 100–200 MB fails only in production, only over the tunnel.

**Fix.** Pick one: chunked/resumable uploads (upload direct-to-disk in ≤90 MB parts), a Cloudflare paid plan, or lower the media ceiling below 100 MB and document it. Flag before go-live. **Effort: M–L** (chunking) / **S** (lower the cap).

---

### 🟢 Low

- **INFRA-1 — Floating image tags.** [docker-compose.prod.yml:62](docker-compose.prod.yml#L62) `cloudflare/cloudflared:latest`, [:40](docker-compose.prod.yml#L40) `nginx:alpine`, [:138](docker-compose.prod.yml#L138) `redis:alpine`, [:114](docker-compose.prod.yml#L114) `mysql:8`. Pin to specific versions/digests for reproducible deploys. `:latest` on cloudflared is the worst offender. **Effort: S.**
- **SEC-4 — Non-expiring API tokens.** [backend/config/sanctum.php:55](backend/config/sanctum.php#L55) `'expiration' => null`. Mobile personal-access tokens live forever; combined with SEC-3 they survive deactivation. Set an expiration (e.g. 20160 min) once the mobile client ships. **Effort: S.**
- **SEC-5 — API JSON responses carry no CSP.** [backend/app/Http/Middleware/SecurityHeaders.php:32](backend/app/Http/Middleware/SecurityHeaders.php#L32) defers CSP to "Phase 7". The SPA HTML surface *does* get a full CSP from nginx ([prod.conf:57](docker/nginx/prod.conf#L57)), so this is minor — JSON isn't a script surface — but adding `Content-Security-Policy: default-src 'none'` to API responses closes the gap cleanly. **Effort: S.**
- **QUAL-1 — Oversized Vue components.** `DealPanel.vue` (832), `LocationDetailView.vue` (690), `ClientProjectView.vue` (568), `AppShell.vue` (535), `CompleteVisitForm.vue` (519). Extract composables/child components for readability. **Effort: M each.**
- **QUAL-2 — `useApi` 419 retry can recurse.** [frontend/src/composables/useApi.js:21-26](frontend/src/composables/useApi.js#L21-L26): a 419 refreshes CSRF and replays the request through the same interceptor; a still-failing replay would loop. Bound in practice, but add a `_retried` flag. **Effort: S.**
- **PERF-2 — Phone search can't use the index.** [backend/app/Modules/Clients/Models/Client.php:134](backend/app/Modules/Clients/Models/Client.php#L134) `whereRaw("RIGHT(REGEXP_REPLACE(phone, ...))")` is a functional predicate, so the `clients.phone` index can't serve it. Inherent to format-agnostic matching; fine at 15–50 users, revisit only at scale. **Effort: —** (accept, or add a normalized `phone_nsn` generated column + index).

---

## 3. Areas verified clean (stated once, per ground rule 3)

- **Zero-deletion:** `BaseModel::delete()` and `forceDelete()` throw ([BaseModel.php:48-61](backend/app/Core/Models/BaseModel.php#L48-L61)). The only hard deletes in the whole codebase are Sanctum tokens and ephemeral `UserDraft` rows — no business data.
- **Audit log integrity:** `ActivityLog` is genuinely append-only — `update()`/`delete()` throw, no `updated_at`, and it records user/role/IP/user-agent + before-after, scrubbing hidden fields ([ActivityLog.php:22-65](backend/app/Modules/Analytics/Models/ActivityLog.php#L22-L65), [LogsActivity.php:16-53](backend/app/Core/Concerns/LogsActivity.php#L16-L53)).
- **Money types & math:** every monetary column is `decimal(12,2)`; all arithmetic goes through a bcmath `Money` helper ([Money.php](backend/app/Modules/Payments/Support/Money.php)). No `float`/`double` on money anywhere. Payment schedules reconcile to the agreed price server-side.
- **Mass assignment / raw SQL:** every model declares `$fillable`; no `$request->all()` into `create/update`; every `whereRaw/selectRaw/orderByRaw/DB::raw` is parameter-bound or a constant expression — no user input in raw fragments.
- **Reserve path concurrency:** `ReserveUnit` correctly uses `lockForUpdate()` + status re-check inside the transaction, and enforces the unique-per-project hold rule. `RecordVersement` locks the schedule row before allocating. (The gaps are only in the *other* four state-transition actions — DB-1, SEC-2.)
- **Route protection:** every module route sits behind `auth:sanctum`; the only unauthenticated routes are `/ping` and the two login endpoints, both throttled (`throttle:login`, 5/min by email+IP). No unprotected write route.
- **Uploads:** content-detected `mimetypes` validation (not extension), size ceilings, private disk, permission-gated streaming with S3 `temporaryUrl` fallback ([MediaController.php](backend/app/Modules/Inventory/Http/Controllers/MediaController.php), [UploadMediaRequest.php](backend/app/Modules/Inventory/Http/Requests/UploadMediaRequest.php)); chat attachments validated the same way.
- **Dependencies:** `composer audit` → *No security vulnerability advisories found.* `npm audit` → *found 0 vulnerabilities.*
- **XSS:** zero `v-html` in the entire frontend.
- **Indexes & FKs:** hot columns are indexed to the query patterns (units `location_id`/`(sale_status,status)`/`(block,stack_floor,position)`; reservations `(hold_status,expires_at)`; versements `(client_project_id,status)`+`paid_on`; next_actions `(state,due_at)`; morph index on `activity_log`); all FKs are `constrained()` — no orphan risk.
- **Prod infrastructure:** MySQL/Redis publish **no** host ports and live on an `internal: true` network with no egress; Redis has `requirepass` + AOF; every service has a restart policy, healthcheck, `no-new-privileges` and log rotation; nginx has real-client-IP recovery via `CF-Connecting-IP`, `limit_req` on the auth endpoints, HSTS, a full CSP, dotfile denial, and an internal-only `.php` location. `TrustProxies` is configured (`trustProxies('*')`, justified by the tunnel topology) — the previously-flagged proxy blocker is resolved.
- **Sessions table:** prod uses `SESSION_DRIVER=database`; the `sessions` table is created by the default skeleton migration — verified, not a gap.

---

## 4. Quick wins (< 30 min each)

1. **SEC-1** — add `&& $project->isVisibleTo($user)` to `StoreDealRequest` and `SyncDealBoxesRequest` `authorize()` (copy `ProposeInSiteVisitRequest`).
2. **FE-1** — add a `submitting` ref + `:loading`/`:disabled` to the Record / Save-schedule / Correct / Deposit buttons.
3. **SEC-3 (partial)** — `$user->tokens()->delete()` inside `SetUserActive` and `CancelUser`.
4. **INFRA-1** — pin `cloudflared` and the three alpine images to fixed versions.
5. **SEC-4** — set `sanctum.expiration`.
6. **SEC-5** — emit `Content-Security-Policy: default-src 'none'` for `api/*` responses.

## 5. Remediation roadmap

**Order (highest risk first):**
1. **DB-1** (Critical) — lock + re-check the unit inside the close transaction. Ship first; it's the only issue that can silently corrupt the sales ledger.
2. **SEC-2** — route reservation release/convert/expiry through `revertToMarket()` + `lockForUpdate` and refuse out-of-band release of no-expiry deal holds.
3. **SEC-1**, **SEC-3**, **FE-1** — the authorization/session/double-submit trio (all small).
4. **PERF-1** — pagination on clients/units.
5. **INFRA-2** — decide the upload strategy vs the 100 MB cap **before** exposing the tunnel publicly.
6. Low items (**INFRA-1, SEC-4, SEC-5, QUAL-\***) as cleanup.

**Pre-Cloudflare go-live checklist:**
- [ ] DB-1 + SEC-2 fixed and covered by a concurrency test (two simultaneous closes on one unit).
- [ ] SEC-1 / SEC-3 fixed; confirm a deactivated user is 401'd on the next request.
- [ ] FE-1 fixed; confirm double-click records one versement.
- [ ] Upload strategy chosen for the 100 MB edge cap (INFRA-2); test a large media upload end-to-end through the tunnel.
- [ ] `APP_DEBUG=false`, `APP_ENV=production`, `SESSION_SECURE_COOKIE=true` verified in the deployed `.env` (templates are correct).
- [ ] Production images pinned (INFRA-1).
- [ ] `SANCTUM_STATEFUL_DOMAINS` / `REVERB_ALLOWED_ORIGINS` set to the real domain; wss through the tunnel smoke-tested.
- [ ] A **restore** drill run from `scripts/backup-db.sh` output (backups exist and are scheduled; prove a restore works).
- [ ] Cloudflare SSL mode **Full (strict)** (tunnel already encrypts origin traffic — never Flexible).

---

*Audit complete. No code was modified. Ready to fix Critical issues first, one at a time, reviewing each before the next.*
