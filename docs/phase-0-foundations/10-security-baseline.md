# Phase 0 · Step 10 — Security Baseline

**Goal:** establish the security posture the whole app inherits. This is the confirmed scope — a
**strong security baseline** (formal GDPR/legal T&C scaffolding is deferred). Every item here is
built once in Phase 0 and relied on by every feature.

> Principle: **the API is the only trust boundary.** The frontend is a convenience; never trust it.
> Validate, authorise, and audit on the server for every write.

---

## 1. Authentication & authorisation

- **Sanctum SPA auth** (Step 06); session cookie is `HttpOnly`, `SameSite=Lax`, `Secure` in prod.
- **Password hashing** with bcrypt (or Argon2id): set `Hash::make()` everywhere; never store plaintext.
  Configure work factor in `config/hashing.php`.
- **Route‑level permissions** (`can:<perm>`) on **every** write route — not just `auth`. A missing
  permission returns **403**.
- **Password policy** in `LoginRequest`/`StoreUserRequest`: min length, `Password::defaults()` with
  `->mixedCase()->numbers()->uncompromised()` (checks HaveIBeenPwned k‑anonymity).

## 2. Rate limiting / throttling

Protect auth and write‑heavy endpoints from brute force and abuse:

```php
// bootstrap/app.php or a RouteServiceProvider — named limiters
RateLimiter::for('login', fn (Request $r) =>
    Limit::perMinute(5)->by($r->input('email').$r->ip()));

RateLimiter::for('api', fn (Request $r) =>
    Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
```

Apply `->middleware('throttle:login')` to the login route and `throttle:api` to the `v1` group.

## 3. CORS (strict allow‑list)

`config/cors.php`: allow only the SPA origin(s), and allow credentials for Sanctum cookies.

```php
'paths' => ['api/*', 'sanctum/csrf-cookie'],
'allowed_methods' => ['*'],
'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:5173')],
'allowed_headers' => ['*'],
'supports_credentials' => true,
```

Never use `allowed_origins => ['*']` together with credentials.

## 4. Secure HTTP headers

Add a `SecurityHeaders` middleware applied globally:

```php
// app/Http/Middleware/SecurityHeaders.php
$response->headers->set('X-Content-Type-Options', 'nosniff');
$response->headers->set('X-Frame-Options', 'DENY');                       // or CSP frame-ancestors
$response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
$response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(self)'); // mic for voice notes
// Production only:
$response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
// Content-Security-Policy: tune to the SPA + CDN origins in Phase 7.
```

## 5. Input validation & mass‑assignment

- **FormRequests are mandatory** for every write — validation is the *only* trust boundary. Controllers
  receive already‑validated data.
- **Mass‑assignment guards:** models use `$fillable` allow‑lists (never a blanket `$guarded = []` on
  user‑writable models). IDs like `role_id`, `status`, `supersedes_id` are set by Actions, not by request input.
- **No raw SQL with interpolation** — only Eloquent / query‑builder bindings, so SQL injection can't occur.

## 6. File‑upload hardening (media foundation)

Media is a Phase 2 feature, but the rules are set now (see
[`../database/02-inventory.md`](../database/02-inventory.md)):

- Validate **mime type and size** server‑side (`->mimetypes([...])->max($kb)`); never trust the extension.
- Store on a **private disk** by default; serve public media via signed URLs / the CDN, not by exposing the disk.
- Randomise stored filenames (`Str::uuid()`); keep the original name only as metadata.
- Strip/ignore executable types; images/video/PDF/PPTX only, per the product.
- Enforce nginx `client_max_body_size` (Step 02) to match the allowed size.

## 7. Secrets & configuration

- All secrets in `.env` (dev) or the platform secret store (prod). **Never in code, never committed.**
- `.env.example` lists every key with secrets blanked.
- `APP_DEBUG=false` and `APP_ENV=production` in prod; debug pages never shown to users.
- Rotate `APP_KEY`, DB and mail credentials out of the dev defaults before any real data.

## 8. Data integrity & auditability (already built)

- **No hard delete** anywhere (`BaseModel`, Step 04) — corrections cancel‑and‑duplicate.
- **Append‑only activity log** (Step 05) captures who/what/when/before→after/IP for every action; it is
  immutable and cannot be edited or deleted.
- **Transactions** wrap multi‑step writes so partial failures roll back cleanly.

## 9. Transport & production notes (enforced in Phase 7)

- **HTTPS everywhere** in production; HSTS on; redirect HTTP→HTTPS at the edge.
- Managed/hardened MySQL with least‑privilege app credentials (not `root`).
- Automated backups; object storage + CDN for media with correct ACLs.

## 10. Baseline tooling checks

- Add `composer audit` and `npm audit` to CI (Step 11) to surface vulnerable dependencies.
- Keep the framework and packages patched.

---

## Checklist / gate

- [ ] Login and API routes are throttled; login limiter keyed by email+IP.
- [ ] CORS allows only the SPA origin with credentials; not `*`.
- [ ] Security headers middleware applied globally (HSTS added in prod).
- [ ] Every write route is guarded by a permission and backed by a FormRequest.
- [ ] Models use `$fillable` allow‑lists; privileged fields set only by Actions.
- [ ] File‑upload validation (mime + size), private disk, randomised names — rules documented.
- [ ] No secrets in the repo; `.env.example` complete; `APP_DEBUG=false` path for prod.
- [ ] `composer audit` / `npm audit` wired into CI.

**Next:** [`11-ci-pipeline.md`](11-ci-pipeline.md)
