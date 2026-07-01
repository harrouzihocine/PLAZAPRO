# Phase 7 — Hardening & Launch

**Ships:** mobile polish, performance, a security pass, backups, and deployment. This phase turns a
feature‑complete app into a production system. Production uses the **same container approach**,
tightened for security and performance. Keep configuration in environment variables, never in code.

---

## 1. Mobile polish & accessibility

- Walk every critical flow at phone width: capture a client, present/reserve a unit, log a call, record
  a payment, chat with a voice note. Each should work in a few taps (§5.4).
- Confirm the stacking plan, media viewer, forms, and chat all reflow cleanly; tap targets ≥ 44px.
- Both themes verified on real devices (gold/white and gold/black).
- Basic a11y: focus states, labels/aria, colour contrast against the gold tokens.

## 2. Performance

- **Backend:** enable caching — `php artisan config:cache route:cache view:cache event:cache`;
  `optimize`. Add/confirm indexes flagged in the DB docs. Eager‑load to kill N+1 (audit with a query
  logger). Paginate every list endpoint.
- **Frontend:** `vite build` produces optimised static assets served by nginx; route‑level code
  splitting (dynamic `import()`); image lazy‑loading; gzip/brotli at the edge.
- **Queue:** ensure the queue worker + scheduler run reliably (see below); move slow work (documents,
  emails, reminders, exports) off the request path.

## 3. Security pass (build on [`phase-0-foundations/10-security-baseline.md`](phase-0-foundations/10-security-baseline.md))

- `APP_DEBUG=false`, `APP_ENV=production`; no stack traces to users.
- **HTTPS everywhere**; HSTS on; HTTP→HTTPS redirect at the edge; finalise the Content‑Security‑Policy
  for the SPA + CDN origins.
- Rotate all secrets out of dev defaults; app connects to MySQL with a **least‑privilege** user (not
  `root`). Object‑storage buckets have correct ACLs (private by default, CDN for public media).
- Re‑run `composer audit` / `npm audit` (now **blocking** in CI); patch findings.
- Re‑verify: every write route is permission‑guarded; uploads validated; no‑delete + immutable audit intact.
- Run a full security review of the diff before launch, against the baseline in
  [`phase-0-foundations/10-security-baseline.md`](phase-0-foundations/10-security-baseline.md).

## 4. Production stack & config

- Same Docker approach, production compose/K8s: nginx serving built SPA + proxying `/api`; php‑fpm app;
  **queue worker**; scheduler; managed/hardened MySQL 8 with automated backups; Redis; object storage +
  CDN for media.
- **Scheduler/worker:** run `php artisan schedule:work` (or cron → `schedule:run`) and
  `php artisan queue:work` as supervised, restarting processes (`restart: unless-stopped`, or
  Supervisor/systemd).
- Config only via environment variables / secret store — never baked into images.

## 5. Backups & migrations on deploy

- **Automated MySQL backups** (managed service or scheduled `mysqldump`/snapshot to object storage),
  with a tested **restore** procedure.
- **Run migrations on deploy** automatically and safely, **with a backup taken first**. Use
  `php artisan migrate --force`; keep migrations backward‑compatible for zero‑downtime where possible.
- Back up uploaded media (object storage versioning/replication).

## 6. Monitoring & observability

- **Log errors centrally** (e.g. Sentry/ELK); alert on error spikes.
- **Watch the queue** (failed jobs table, worker health) and the scheduler, so issues surface before
  users report them.
- Uptime + latency checks on the API and SPA; DB slow‑query log reviewed.

## 7. Go‑live checklist (guide §10.2)

- [ ] Optimised images: compiled Vue assets served static; PHP config/route/view caches enabled.
- [ ] Real services: hardened MySQL + automated backups; object storage + CDN for media; HTTPS everywhere.
- [ ] Migrations run automatically and safely on deploy, backup taken first.
- [ ] Errors logged centrally; queue and scheduler monitored.
- [ ] Security pass complete: debug off, secrets rotated, least‑privilege DB user, CSP/HSTS set,
      audits clean, every route guarded.
- [ ] Full regression: each phase's key rules pass in CI; app usable on phone + desktop in both themes.

**Add‑on:** [`phase-8-mobile-push.md`](phase-8-mobile-push.md) — a native mobile app for field agents on
the same API (token auth, push).

**Back to:** [`README.md`](README.md) · [`testing-and-going-live.md`](testing-and-going-live.md)
