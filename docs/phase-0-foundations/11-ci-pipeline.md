# Phase 0 · Step 11 — Continuous Integration

**Goal:** run the whole test suite and the style checks on **every push**, so `main` is always green.
Shared standards are enforced, not just hoped for.

---

## 1. Install the tooling

**Backend (PHP):**

Laravel ships **PHPUnit** and **Pint** by default — that's what this repo uses (the `tests/` are
PHPUnit feature tests, run via `php artisan test`). Pest is a fine alternative if you prefer its
syntax:

```bash
# Optional — only if you want Pest instead of PHPUnit:
docker compose exec app composer require --dev pestphp/pest pestphp/pest-plugin-laravel
docker compose exec app php artisan pest:install
```

- **Laravel Pint** — PSR‑12 auto‑formatter. Config `backend/pint.json` (preset `laravel`,
  `declare_strict_types` on). Run `./vendor/bin/pint` to fix, `--test` to check in CI.
- **PHPUnit / Pest** — feature tests hitting the API; run with `php artisan test`.

**Frontend (JS/Vue):**

```bash
docker compose exec node npm install -D eslint@8 eslint-plugin-vue@9 eslint-config-prettier prettier vitest @vue/test-utils jsdom
```

Config lives in `frontend/.eslintrc.cjs` (`eslint:recommended` + `plugin:vue/vue3-recommended` +
`prettier` last) and `frontend/.prettierrc.json`.

- **ESLint** — correctness; **Prettier** — formatting; **Vitest** — component/composable tests.

Add scripts to `frontend/package.json`:

```json
"scripts": {
  "dev": "vite",
  "build": "vite build",
  "test": "vitest run",
  "lint": "eslint . --ext .js,.vue",
  "format": "prettier --check ."
}
```

## 2. Pre‑commit formatting (optional but recommended)

Run Pint and ESLint/Prettier before each commit so nothing unformatted lands:

```bash
# backend
docker compose exec app ./vendor/bin/pint
# frontend
docker compose exec node npm run lint && docker compose exec node npm run format
```

Optionally wire a Git hook (e.g. via `husky` + `lint-staged` on the frontend, or a simple
`.git/hooks/pre-commit` script) so this is automatic.

## 3. GitHub Actions workflow

`/.github/workflows/ci.yml` — runs on every push and pull request:

```yaml
name: CI
on: [push, pull_request]

jobs:
  backend:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8
        env: { MYSQL_DATABASE: plaza_test, MYSQL_ROOT_PASSWORD: secret }
        ports: ['3306:3306']
        options: >-
          --health-cmd="mysqladmin ping -psecret" --health-interval=10s
          --health-timeout=5s --health-retries=5
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', extensions: pdo_mysql, bcmath, intl, gd, redis, coverage: none }
      - name: Install deps
        working-directory: backend
        run: composer install --no-interaction --prefer-dist
      - name: Prepare env
        working-directory: backend
        run: |
          cp .env.example .env
          php artisan key:generate
          sed -i 's/DB_HOST=mysql/DB_HOST=127.0.0.1/; s/DB_DATABASE=plaza/DB_DATABASE=plaza_test/' .env
      - name: Pint (style)
        working-directory: backend
        run: ./vendor/bin/pint --test
      - name: Migrate + Test
        working-directory: backend
        run: |
          php artisan migrate --force
          php artisan test
      - name: Dependency audit
        working-directory: backend
        run: composer audit || true

  frontend:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: '20', cache: 'npm', cache-dependency-path: frontend/package-lock.json }
      - name: Install
        working-directory: frontend
        run: npm ci
      - name: Lint + Format check
        working-directory: frontend
        run: npm run lint && npm run format
      - name: Unit tests
        working-directory: frontend
        run: npm run test
      - name: Dependency audit
        working-directory: frontend
        run: npm audit --audit-level=high || true
```

> `pint --test` fails the build on unformatted code (it does not modify files in CI). The
> `|| true` on the audit steps keeps advisories visible without blocking early on; tighten to blocking
> in Phase 7.

## 4. Branch protection

On GitHub, protect `main`:

- Require the `backend` and `frontend` CI checks to pass before merge.
- Require at least one approving review (Definition of Done, [`../conventions/git-workflow.md`](../conventions/git-workflow.md)).
- Disallow direct pushes to `main`.

---

## Checklist / gate

- [ ] Pint, Pest installed (backend); ESLint, Prettier, Vitest installed (frontend).
- [ ] `pint --test`, `php artisan test`, `npm run lint`, `npm run test` all runnable locally.
- [ ] `.github/workflows/ci.yml` runs style checks + tests for both halves on every push/PR.
- [ ] `main` is branch‑protected: green CI + one review required, no direct pushes.

**Next:** [`12-phase-0-checklist.md`](12-phase-0-checklist.md)
