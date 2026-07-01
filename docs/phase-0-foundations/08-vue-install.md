# Phase 0 · Step 08 — Install & Configure Vue 3

**Goal:** a Vue 3 single‑page app built with Vite — responsive first, themed from the start, organised
by feature to mirror the backend modules. It holds **no business rules**; it presents data and calls
the API.

Run commands **inside the `node` container**.

---

## 1. Scaffold the Vue app into `frontend/`

```bash
docker compose exec node sh -c "npm create vite@latest . -- --template vue"
docker compose exec node npm install
```

Then add the core libraries:

```bash
docker compose exec node npm install vue-router@4 pinia axios
docker compose exec node npm install -D tailwindcss@3 postcss autoprefixer
docker compose exec node npx tailwindcss init -p
```

## 2. The stack (guide Table 5.1)

| Tool | Role |
|------|------|
| Vue 3 (Composition API) | UI framework; `<script setup>` for concise components |
| Vite | dev server + build tool |
| Vue Router | client‑side routing |
| Pinia | state management (stores per feature) |
| Axios | talks to the Laravel API |
| Tailwind CSS | utility styling, wired to gold/black design tokens |

## 3. Feature‑based folder structure (guide §5.2)

```
frontend/src/
├── assets/styles/      # tokens.css (themes), tailwind entry (Step 09)
├── components/base/    # BaseButton, BaseInput, BaseCard (themed, reused)
├── composables/        # useApi, useTheme, useAuth
├── layouts/            # AppShell (sidebar, top bar, mobile nav)  (Step 09)
├── features/
│   ├── inventory/      # views/, components/, store.js, api.js
│   ├── clients/
│   ├── pipeline/
│   ├── payments/
│   └── ...             # one folder per module
├── router/index.js
└── main.js
```

Create it:

```bash
docker compose exec node sh -c '
mkdir -p src/assets/styles src/components/base src/composables src/layouts src/router
for f in settings inventory clients pipeline payments collaboration analytics; do
  mkdir -p src/features/$f/{views,components}
  : > src/features/$f/store.js
  : > src/features/$f/api.js
done
'
```

## 4. Vite config — proxy `/api` to the backend

So the SPA calls same‑origin `/api/...` in dev (and Sanctum cookies work):

```js
// frontend/vite.config.js
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  server: {
    host: true,          // listen on 0.0.0.0 inside the container
    port: 5173,
    proxy: {
      '/api':     { target: 'http://nginx:80', changeOrigin: true },
      '/sanctum': { target: 'http://nginx:80', changeOrigin: true },
    },
  },
})
```

> In dev you open the app at `http://localhost:5173` (Vite/HMR). Nginx on `:8080` serves the built SPA
> in production. Both reach the API via `/api`.

## 5. The API layer — one Axios wrapper (`useApi`)

All network calls go through one small wrapper, so authentication, error handling and the base URL
live in a single place (guide §5.5):

> **Auth mode — decided:** the web SPA uses **Sanctum SPA (cookie) authentication**, not Bearer
> tokens. It is the officially recommended, more secure choice for a first‑party same‑site SPA — the
> session lives in an `HttpOnly` cookie, so no token sits in JS/`localStorage` to be stolen via XSS.
> (The guide's §5.5 snippet shows a `Bearer` header for brevity; we standardise on cookies. Sanctum can
> still issue personal‑access tokens later for a **native mobile** client without changing this.)

```js
// src/composables/useApi.js
import axios from 'axios'
import { useAuth } from './useAuth'

const api = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,               // send the Sanctum session cookie (SPA mode)
  withXSRFToken: true,                 // echo the XSRF-TOKEN cookie as the X-XSRF-TOKEN header
  headers: { Accept: 'application/json' },
})

api.interceptors.response.use(
  (res) => res,
  async (error) => {
    const status = error.response?.status
    if (status === 401) useAuth().logoutLocal()             // session expired → clear + redirect to login
    if (status === 419) {                                    // CSRF token mismatch → refresh then retry once
      await getCsrf()
      return api(error.config)
    }
    return Promise.reject(error)
  },
)

// Call once before the first stateful request (e.g. before login):
export function getCsrf() { return axios.get('/sanctum/csrf-cookie', { withCredentials: true }) }
export function useApi() { return api }
// Components call api.get('/units'), never raw fetch(). Auth is via the session cookie — no token header.
```

## 6. Router, Pinia, and app bootstrap

```js
// src/router/index.js
import { createRouter, createWebHistory } from 'vue-router'
import { useAuth } from '@/composables/useAuth'

const routes = [
  { path: '/login', name: 'login', component: () => import('@/features/settings/views/LoginView.vue') },
  {
    path: '/', component: () => import('@/layouts/AppShell.vue'),
    meta: { requiresAuth: true },
    children: [
      { path: '', name: 'dashboard', component: () => import('@/features/analytics/views/DashboardView.vue') },
      // feature routes are added per phase
    ],
  },
]

const router = createRouter({ history: createWebHistory(), routes })

router.beforeEach(async (to) => {
  const auth = useAuth()
  if (to.meta.requiresAuth && !auth.isAuthenticated.value) {
    await auth.fetchMe().catch(() => {})
    if (!auth.isAuthenticated.value) return { name: 'login' }
  }
})

export default router
```

```js
// src/main.js
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import router from './router'
import App from './App.vue'
import './assets/styles/tailwind.css'   // includes tokens.css (Step 09)

createApp(App).use(createPinia()).use(router).mount('#app')
```

`useAuth` is a small Pinia store exposing `isAuthenticated`, `user`, `permissions`, `fetchMe()`,
`login()`, `logout()`, `logoutLocal()` — it wraps the cookie‑based auth endpoints from Step 06. It holds
**no token** (auth is the session cookie); `login()` calls `getCsrf()` first, then `POST /auth/login`.

## 7. Verify FE ↔ API

With the stack up, open `http://localhost:5173`. In the browser console:

```js
fetch('/api/v1/ping').then(r => r.json()).then(console.log)   // {pong:true,...} via the Vite proxy
```

A successful `pong` proves the SPA reaches the Laravel API through the proxy — the two halves are
talking. (Remove the temporary `/ping` route afterwards.)

---

## Checklist / gate

- [ ] Vue 3 + Vite installed in `frontend/`; dev server reachable at `http://localhost:5173`.
- [ ] Vue Router, Pinia, Axios, Tailwind installed.
- [ ] Feature folders created, mirroring the backend modules.
- [ ] `useApi` wrapper is the only place Axios is configured; components never call `fetch` directly.
- [ ] The SPA reaches `/api/v1/*` through the Vite proxy (ping returns JSON).

**Next:** [`09-theming-and-appshell.md`](09-theming-and-appshell.md)
