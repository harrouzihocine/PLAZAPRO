# Phase 0 · Step 01 — Repository & Project Layout

**Goal:** create the mono‑repo that keeps the backend, frontend and Docker files versioned together and
startable with one command.

---

## 1. Initialise the repository

The project lives at the repo root (`/home/plazapro/www` is the working copy). Initialise Git and set
`main` as the default branch:

```bash
cd /home/plazapro/www
git init -b main
```

> The reference build guide is kept under [`docs/reference/`](../reference/) — do not delete it; it is
> the source of truth this playbook is derived from.

## 2. Create the folder skeleton

```bash
mkdir -p docker/php docker/nginx backend frontend
```

Target layout (the "mono‑repo"):

```
www/                        # repo root
├── docker/                 # Dockerfiles & service configs
│   ├── php/Dockerfile
│   └── nginx/default.conf
├── backend/                # Laravel API            (Step 03)
├── frontend/               # Vue 3 SPA              (Step 08)
├── docs/                   # this playbook
├── docker-compose.yml      # the whole dev stack    (Step 02)
├── .env                    # shared dev settings    (git‑ignored)
├── .env.example            # committed template
├── .editorconfig
├── .gitignore
└── README.md
```

## 3. Root `.gitignore`

Create `/home/plazapro/www/.gitignore`:

```gitignore
# Secrets & local env
.env
*.env.local

# Backend (Laravel)
/backend/vendor/
/backend/node_modules/
/backend/public/build/
/backend/storage/*.key
/backend/.env
/backend/storage/logs/*.log
/backend/storage/framework/{cache,sessions,views}/*

# Frontend (Vue)
/frontend/node_modules/
/frontend/dist/
/frontend/.env

# OS / editor
.DS_Store
Thumbs.db
.idea/
.vscode/*
!.vscode/extensions.json

# Docker
/docker/**/data/
```

> Laravel and Vue each ship their own `.gitignore` inside `backend/` and `frontend/`; keep those too.
> The root file covers cross‑cutting artefacts.

## 4. `.editorconfig` (one source of truth for whitespace)

Create `/home/plazapro/www/.editorconfig`:

```ini
root = true

[*]
charset = utf-8
end_of_line = lf
insert_final_newline = true
trim_trailing_whitespace = true
indent_style = space
indent_size = 4

[*.{js,ts,vue,json,yml,yaml,css}]
indent_size = 2

[*.md]
trim_trailing_whitespace = false
```

## 5. Environment strategy

- **`.env`** — real local values, **git‑ignored**, never committed.
- **`.env.example`** — a committed template with every key present but secrets blanked. Anyone can
  `cp .env.example .env` and fill in the gaps.
- Secrets live only in `.env` (dev) or the platform's secret store (prod) — **never in code**. See
  [`10-security-baseline.md`](10-security-baseline.md).

Create `/home/plazapro/www/.env.example` (shared stack settings — service‑specific values are set in
the backend/frontend envs in later steps):

```dotenv
# Shared dev stack
COMPOSE_PROJECT_NAME=plaza
APP_PORT=8080
VITE_PORT=5173
MAILPIT_PORT=8025

# MySQL (dev only — never reuse these in prod)
MYSQL_DATABASE=plaza
MYSQL_ROOT_PASSWORD=secret
MYSQL_PORT=3306
```

Then `cp .env.example .env` to create your local copy.

## 6. First commit

```bash
git add .gitignore .editorconfig .env.example README.md docs
git commit -m "chore: initialise mono-repo layout and docs playbook"
```

> Commit convention (short, present tense, scoped) is defined in
> [`../conventions/git-workflow.md`](../conventions/git-workflow.md). Do **not** commit `.env`.

---

## Checklist / gate

- [ ] Repo initialised with `main` as default branch.
- [ ] `docker/`, `backend/`, `frontend/` folders exist.
- [ ] `.gitignore`, `.editorconfig`, `.env.example` committed; `.env` created locally and **ignored**.
- [ ] Running `git status` shows `.env` as ignored (not staged).

**Next:** [`02-docker-stack.md`](02-docker-stack.md)
