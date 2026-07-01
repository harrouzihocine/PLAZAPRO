# Phase 8 — Mobile & Push (add‑on)

**Ships:** a native mobile app for field agents, over the **same API and database** — stateless token
auth, device registration, and push notifications. This is an **add‑on track**, not a new backend: the
mobile app is a second client of `/api/v1`, exactly as the Vue SPA is.

> **The golden rule still holds** — the app talks to the **API**, never to MySQL directly. Same
> backend → same database, same RBAC, same audit trail, for free.

Build as vertical slices, mobile‑first. Slice 1 is already implemented (see below); the rest layer on
top and slices 3–4 depend on Phase 5 (notifications), so schedule accordingly.

---

## Why the stack is ready

- The backend is **API‑first**: every module already returns JSON under `/api/v1`. Nothing about the
  business logic is web‑only.
- The `User` model already has Sanctum's `HasApiTokens` **and** `Notifiable` — token auth and the
  notification pipeline need **no new backend dependencies** to start.
- RBAC resolves through the user's single role in `Gate::before`
  ([`RbacServiceProvider`](../backend/app/Providers/RbacServiceProvider.php)), independent of *how* the
  request authenticated. A token‑authenticated agent gets exactly the same permissions as in the SPA.

## Auth: two modes, one identity

| Client | Mode | Transport |
|--------|------|-----------|
| Vue web SPA | Sanctum **stateful** (session cookie + CSRF) | same‑origin, `withCredentials` |
| Mobile / external | Sanctum **personal access token** | `Authorization: Bearer <token>` |

Sanctum accepts either on `auth:sanctum` routes, so **every existing protected endpoint already works
for mobile** — no per‑route changes.

---

## Slices in this phase

### 1. Bearer‑token auth  ✅ *implemented*
- **API:** `POST /api/v1/auth/token` (issue — email + password + `device_name`, throttled like login),
  `DELETE /api/v1/auth/token` (revoke the current token — mobile logout). Existing `GET /auth/me`
  works unchanged with the token.
- **Rules:** credentials verified without opening a session; inactive users are refused; **one token
  per `device_name`** (re‑login replaces the old one); login is recorded in the activity log.
- **RBAC:** unchanged — the token authenticates as the user; `can:<permission>` still resolves through
  the role. Tokens are issued with full ability (`*`); scope them per‑ability later if needed.
- **Where:** [`AuthController`](../backend/app/Modules/Settings/Http/Controllers/AuthController.php),
  [`IssueTokenRequest`](../backend/app/Modules/Settings/Http/Requests/IssueTokenRequest.php),
  [`Settings/routes.php`](../backend/app/Modules/Settings/routes.php); tests in
  [`TokenAuthTest`](../backend/tests/Feature/Auth/TokenAuthTest.php).

### 2. Public exposure & CORS  *(infra, no app code)*
- Today everything is `localhost` / local network (`APP_URL`, `SANCTUM_STATEFUL_DOMAINS`). Field agents
  on the internet need the API reachable over a **public domain with HTTPS** (reverse proxy + TLS; the
  existing nginx service terminates or forwards).
- Publish a CORS config (`config/cors.php`) allowing the mobile origin for `api/*`. Native token calls
  are not browser‑CORS‑gated, but a Capacitor webview and any web‑push flow are — set it explicitly.
- **Do not** add the mobile origin to `SANCTUM_STATEFUL_DOMAINS`: mobile is stateless (token), not
  cookie‑based. Keep the two modes cleanly separated.
- Token lifetime: `config/sanctum.php` `expiration` is `null` (long‑lived, typical for mobile). If you
  want rotation, set an expiry and add a refresh step; document the choice.

### 3. Device registration  *(depends on Phase 5)*
- **API:** `POST /api/v1/devices` (register a push token: platform `ios|android`, provider token),
  `DELETE /api/v1/devices/{token}` (on logout / token change). A `device_tokens` table keyed by user.
- **Rules:** unique per (user, provider token); prune stale/invalid tokens reported by FCM.
- **Permissions:** any authenticated user (registers **their own** devices only).

### 4. Push notifications via FCM  *(depends on Phase 5)*
- Adds a **push channel** on top of Phase 5's database‑channel notifications: the same `Notification`
  classes fan out to the DB (in‑app inbox) **and** to FCM (lock‑screen push) for the user's registered
  devices, dispatched on the **queue worker**.
- **Delivery:** `laravel-notification-channels/fcm` + a Firebase project (Android/iOS). Payload carries
  a deep link to the subject (client, unit, payment, task) so a tap opens the right screen.
- **Reuses:** Phase 3 reminders and payment/visit events — no new event sources, just a new channel.
- **Permissions:** `notifications.view` (as Phase 5).

### 5. Mobile app shell — Capacitor + Ionic Vue  *(client)*
- Reuse the team's Vue 3 skills: an **Ionic Vue** app in a `mobile/` workspace (or its own repo) that
  calls `/api/v1` with the Bearer token from slice 1, stored in secure storage.
- Native bits: push registration (slice 3), camera for unit media, mic for voice notes (Phase 5 chat).
- Ship the agent‑critical paths first (login, clients, units, reservations, notifications); it does not
  need feature parity with the desktop SPA on day one.

---

## Key rules to test

- [ ] A valid credential + `device_name` returns a working Bearer token; it authenticates `/auth/me`.
- [ ] Inactive users cannot obtain a token; wrong password is rejected; no token row is created.
- [ ] Re‑login on the same `device_name` yields exactly one live token, not a pile.
- [ ] `DELETE /auth/token` revokes the current token; it no longer authenticates.
- [ ] *(Phase 5)* A notification reaches both the DB inbox and the user's registered devices.
- [ ] *(Phase 5)* Deregistered / invalid device tokens stop receiving push and are pruned.

## Definition of Done (gate)

Token auth works end to end and is tested (slice 1 — done). For the full phase: API reachable over
HTTPS from outside; mobile agents log in, use the core flows, and receive push that deep‑links to the
subject; RBAC enforced identically to the SPA; actions logged; feature tests green; Pint/ESLint clean;
reviewed PR.

## Sequencing

Slice 1 (token auth) and slice 2 (exposure/CORS) can ship now — they depend on nothing else. Slices 3–4
(devices + push) build on **Phase 5 — Collaboration** notifications, so land them after Phase 5. Slice 5
(the app shell) can start against token auth in parallel and grow as modules land.

**Depends on:** [`phase-5-collaboration.md`](phase-5-collaboration.md) (notifications) ·
**Back to:** [`README.md`](README.md) · [`phase-7-hardening-launch.md`](phase-7-hardening-launch.md)
