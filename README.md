# PLAZA PRO — Real Estate Sales CRM

Laravel (API, PHP 8.3) · Vue 3 (Vite SPA) · MySQL 8 · Docker · Ubuntu

A mono‑repo: the Laravel API (`backend/`), the Vue 3 SPA (`frontend/`), and the Docker stack that runs
them (`docker/`, `docker-compose.yml`) are versioned together.

## Documentation

The full, step‑by‑step build playbook lives in **[`docs/`](docs/README.md)** — start there. It covers
the architecture, conventions, the proposed database schema, and every phase (0 → 7) as a tested
vertical slice.

## Quick start (on a machine with Docker + Compose)

```bash
cp .env.example .env
docker compose up -d            # start nginx, app, queue, node, mysql, redis, mailpit
```

Then follow the numbered steps in [`docs/phase-0-foundations/`](docs/phase-0-foundations/) to install
Laravel and Vue inside the containers, wire auth/RBAC, the base model + audit log, theming, and CI.

- App (via nginx): http://localhost:8080
- Vite dev server: http://localhost:5173
- Mailpit (dev mail): http://localhost:8025

## Repository layout

```
.
├── backend/            # Laravel API            (install per docs/phase-0-foundations/03)
├── frontend/           # Vue 3 SPA              (install per docs/phase-0-foundations/08)
├── docker/             # php Dockerfile, nginx config
├── docker-compose.yml  # the whole dev stack
├── .github/workflows/  # CI (Pint/Pest, ESLint/Prettier/Vitest)
├── docs/               # the build playbook
├── .env.example        # shared stack settings (copy to .env)
├── .editorconfig
└── .gitignore
```

## Foundations this project guarantees

No hard deletes (records are cancelled, corrections cancel‑and‑duplicate) · append‑only activity log ·
one role per user + `is_agent` flag · dynamic lists behind every dropdown · versioned media ·
gold/white + gold/black themes · mobile‑first. See [`docs/00-overview-and-conventions.md`](docs/00-overview-and-conventions.md).
