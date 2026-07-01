# Phase 0 · Step 09 — Theming & the Responsive AppShell

**Goal:** the gold/white (day) + gold/black (night) design‑token system, and a mobile‑first `AppShell`
that every screen lives inside. Colour is **never hard‑coded** in a component — the whole palette is
defined once as CSS variables; a single toggle swaps the theme and every component re‑themes instantly.
The gold accent is **identical** in both modes, so the brand feels consistent day and night.

---

## 1. Design tokens — `src/assets/styles/tokens.css`

```css
/* src/assets/styles/tokens.css */
:root {                       /* light: gold on white */
  --color-bg:         #FFFFFF;
  --color-surface:    #FAF6E9;
  --color-text:       #1A1A1A;
  --color-primary:    #C9A227;   /* gold */
  --color-on-primary: #1A1A1A;
  --radius:           12px;
}

[data-theme="dark"] {         /* night: gold on black */
  --color-bg:         #111111;
  --color-surface:    #1C1C1C;
  --color-text:       #F0F0F0;
  --color-primary:    #C9A227;   /* same gold */
  --color-on-primary: #1A1A1A;
}
```

> Add semantic tokens as needed (`--color-muted`, `--color-danger`, `--color-border`) but keep the
> **gold `--color-primary` identical** across themes.

## 2. Wire Tailwind to the tokens

```js
// frontend/tailwind.config.js
export default {
  content: ['./index.html', './src/**/*.{vue,js,ts}'],
  theme: {
    extend: {
      colors: {
        bg:      'var(--color-bg)',
        surface: 'var(--color-surface)',
        primary: 'var(--color-primary)',
        ink:     'var(--color-text)',
        'on-primary': 'var(--color-on-primary)',
      },
      borderRadius: { token: 'var(--radius)' },
    },
  },
  plugins: [],
}
```

```css
/* src/assets/styles/tailwind.css  (imported in main.js) */
@import './tokens.css';
@tailwind base;
@tailwind components;
@tailwind utilities;

html, body, #app { background: var(--color-bg); color: var(--color-text); min-height: 100%; }
```

Utility classes now follow the theme automatically: `bg-surface text-ink rounded-token`,
`bg-primary text-on-primary`.

## 3. The theme toggle — `useTheme` (persisted)

Switching theme is one line; persist the choice and respect the OS preference on first load:

```js
// src/composables/useTheme.js
import { ref } from 'vue'

const KEY = 'plaza-theme'
const isNight = ref(false)

function apply() {
  document.documentElement.dataset.theme = isNight.value ? 'dark' : 'light'
  localStorage.setItem(KEY, isNight.value ? 'dark' : 'light')
}

export function useTheme() {
  function init() {
    const saved = localStorage.getItem(KEY)
    isNight.value = saved
      ? saved === 'dark'
      : window.matchMedia('(prefers-color-scheme: dark)').matches
    apply()
  }
  function toggle() { isNight.value = !isNight.value; apply() }
  return { isNight, init, toggle }
}
```

Call `useTheme().init()` once in `App.vue`'s `onMounted`.

## 4. The responsive AppShell (guide §5.4)

One layout provides a **sidebar on desktop** and a **bottom navigation bar on mobile**, from the same
component. Design **mobile‑first**: single column by default, add columns at `sm:`/`md:`/`lg:`.

```vue
<!-- src/layouts/AppShell.vue -->
<script setup>
import { useTheme } from '@/composables/useTheme'
import { useAuth } from '@/composables/useAuth'
const { isNight, toggle } = useTheme()
const { user, logout } = useAuth()
</script>

<template>
  <div class="min-h-screen bg-bg text-ink">
    <!-- Top bar -->
    <header class="flex items-center justify-between px-4 h-14 bg-surface">
      <span class="font-semibold text-primary">PLAZA PRO</span>
      <div class="flex items-center gap-3">
        <button @click="toggle" :aria-label="isNight ? 'Switch to day' : 'Switch to night'">
          {{ isNight ? '☀️' : '🌙' }}
        </button>
        <button @click="logout" class="text-sm">Logout</button>
      </div>
    </header>

    <div class="md:flex">
      <!-- Sidebar: desktop only -->
      <aside class="hidden md:block md:w-60 bg-surface min-h-[calc(100vh-3.5rem)] p-3">
        <!-- <NavLinks /> -->
      </aside>

      <!-- Routed content -->
      <main class="flex-1 p-4 pb-20 md:pb-4"><router-view /></main>
    </div>

    <!-- Bottom nav: mobile only -->
    <nav class="md:hidden fixed bottom-0 inset-x-0 h-16 bg-surface flex items-center justify-around">
      <!-- generous tap targets -->
    </nav>
  </div>
</template>
```

### Mobile‑first rules (field agents work on phones — this is the primary experience)

- Start with a **single‑column** layout; add columns at larger breakpoints (`sm:`/`md:`/`lg:`).
- **Bottom nav on mobile, sidebar on desktop**, both from this one `AppShell`.
- **Generous tap targets** (min 44×44px); the present‑a‑unit and log‑a‑call flows work in a few taps.
- **Test at phone width continuously** — the stacking plan, media viewer and forms must all reflow cleanly.

## 5. Base components consume tokens (never raw colours)

```vue
<!-- src/components/base/BaseButton.vue -->
<template>
  <button class="bg-primary text-on-primary rounded-token px-4 py-2 min-h-[44px] disabled:opacity-50">
    <slot />
  </button>
</template>
```

Build `BaseButton`, `BaseInput`, `BaseCard`, `BaseModal` early; every feature reuses them, so theming
and accessibility are solved once.

---

## Checklist / gate

- [ ] `tokens.css` defines both themes with the **same gold** `--color-primary`.
- [ ] Tailwind colours resolve to the CSS variables; no hard‑coded hex in components.
- [ ] `useTheme` toggles `data-theme`, persists to `localStorage`, and respects OS preference on first load.
- [ ] `AppShell` shows a sidebar on desktop and a bottom nav on mobile from one component.
- [ ] Layout is single‑column on phone and reflows cleanly; tap targets ≥ 44px.
- [ ] Toggling the theme re‑themes the whole app instantly, in both light and dark.

**Next:** [`10-security-baseline.md`](10-security-baseline.md)
