# Phase 0 · Step 02 — The Docker Stack

**Goal:** define every container, how they connect, and what persists — in one `docker-compose.yml`.
This file is the backbone of your environment: read it once and you understand the whole local setup.

Topology (see [`../reference/guide-figures.md`](../reference/guide-figures.md) Figure 3.1):

```
Ubuntu host · Docker Engine · network: plaza-net
  nginx (8080) → app (php-fpm 8.3, Laravel)      … the browser hits localhost:8080
  node  (5173)  Vite dev server + HMR (Vue 3)
  mysql (3306)  MySQL 8, volume db-data
  redis         cache · queues · sessions
  queue         Laravel queue worker (same image as app) — emails, document generation, notifications
  mailpit (8025) catches dev e-mails
```

---

## 1. `docker-compose.yml` (repo root)

```yaml
# docker-compose.yml
services:
  nginx:
    image: nginx:alpine
    ports: ["${APP_PORT:-8080}:80"]
    volumes:
      - ./backend:/var/www/html
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf
    depends_on: [app]
    networks: [plaza-net]

  app:                         # Laravel + PHP-FPM (the heart of the backend)
    build: ./docker/php
    volumes: ["./backend:/var/www/html"]
    depends_on: [mysql, redis]
    networks: [plaza-net]

  queue:                       # async worker — same image as app
    build: ./docker/php
    command: php artisan queue:work --tries=3 --timeout=120
    volumes: ["./backend:/var/www/html"]
    depends_on: [app, mysql, redis]
    restart: unless-stopped
    networks: [plaza-net]

  node:                        # Vue dev server (Vite)
    image: node:20-alpine
    working_dir: /app
    command: sh -c "npm install && npm run dev -- --host"
    ports: ["${VITE_PORT:-5173}:5173"]
    volumes: ["./frontend:/app"]
    networks: [plaza-net]

  mysql:
    image: mysql:8
    environment:
      MYSQL_DATABASE: ${MYSQL_DATABASE:-plaza}
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD:-secret}
    ports: ["${MYSQL_PORT:-3306}:3306"]
    volumes: ["db-data:/var/lib/mysql"]
    networks: [plaza-net]
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-p${MYSQL_ROOT_PASSWORD:-secret}"]
      interval: 10s
      timeout: 5s
      retries: 5

  redis:
    image: redis:alpine
    networks: [plaza-net]

  mailpit:                     # catches dev e-mails
    image: axllent/mailpit
    ports: ["${MAILPIT_PORT:-8025}:8025"]
    networks: [plaza-net]

volumes:
  db-data:

networks:
  plaza-net:
```

> The `queue` worker and the `mysql` healthcheck are additions over the guide's minimal example, drawn
> from the container‑topology figure and from operational best practice (the guide shows a dedicated
> queue worker for emails, document generation and notifications).

## 2. PHP image — `docker/php/Dockerfile`

```dockerfile
# docker/php/Dockerfile
FROM php:8.3-fpm-alpine

# System deps for common Laravel extensions + image handling
RUN apk add --no-cache \
      git curl zip unzip icu-dev oniguruma-dev libzip-dev \
      libpng-dev libjpeg-turbo-dev freetype-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql bcmath intl zip gd opcache

# Redis extension (cache / queue / session driver)
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
# Match the host user so bind-mounted files are writable without root-owned artefacts
ARG UID=1000
ARG GID=1000
RUN addgroup -g ${GID} app 2>/dev/null || true \
    && adduser -D -u ${UID} -G www-data app 2>/dev/null || true
```

> `opcache` is enabled for speed; production config caching is covered in
> [`../phase-7-hardening-launch.md`](../phase-7-hardening-launch.md).

## 3. Nginx — `docker/nginx/default.conf`

```nginx
# docker/nginx/default.conf
server {
    listen 80;
    server_name _;
    root /var/www/html/public;
    index index.php;

    client_max_body_size 50M;          # media/PPTX uploads (tune per Media policy)

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass app:9000;         # "app" = php-fpm service on plaza-net
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }   # block dotfiles
}
```

## 4. Bring the stack up

```bash
cd /home/plazapro/www
docker compose up -d           # build images + start all services (detached)
docker compose ps              # all services "running"/"healthy"
docker compose logs -f app     # follow Laravel logs
```

> The `app` and `node` services will have nothing to serve until Laravel (Step 03) and Vue (Step 08)
> are installed. That is expected — bring them up again after those steps.

## 5. Daily commands

```bash
docker compose up -d                         # start the whole stack
docker compose down                          # stop everything (keeps volumes/data)
docker compose down -v                       # stop AND wipe db-data (destructive)
docker compose logs -f app                   # follow the Laravel logs

# Run tooling INSIDE the app container (uses the container's PHP, not the host's)
docker compose exec app php artisan migrate
docker compose exec app php artisan make:model Unit -m
docker compose exec app composer require <package>

# Frontend (inside the node container)
docker compose exec node npm run build
```

### Recommended shell aliases

Add to `~/.bashrc` so the long commands never slow you down:

```bash
alias dc='docker compose'
alias dart='docker compose exec app php artisan'   # e.g. `dart migrate`
alias dcomposer='docker compose exec app composer'
alias dnode='docker compose exec node'
```

---

## Checklist / gate

- [ ] `docker compose up -d` starts nginx, app, queue, node, mysql, redis, mailpit.
- [ ] `docker compose ps` shows mysql **healthy**.
- [ ] Mailpit UI reachable at `http://localhost:8025`.
- [ ] `docker compose exec app php -v` prints **PHP 8.3** with pdo_mysql, redis, gd, intl, opcache.
- [ ] Aliases added and `dart --version` works.

**Next:** [`03-laravel-install.md`](03-laravel-install.md)
