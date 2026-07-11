import { createApp } from 'vue'
import { createPinia } from 'pinia'
import PrimeVue from 'primevue/config'
import Tooltip from 'primevue/tooltip'
import router from '@/router'
import App from '@/App.vue'
import preset from '@/theme/preset'
import { i18n, initLocale, currentLocale } from '@/i18n'
import { PRIMEVUE_LOCALES } from '@/i18n/primevue'
import '@fontsource-variable/inter'
// Arabic glyphs everywhere (client names are often Arabic even in the French/
// English UI), not just when the UI language is Arabic.
import '@fontsource-variable/noto-sans-arabic'
import 'flag-icons/css/flag-icons.min.css'
import '@/assets/styles/tailwind.css'
import 'primeicons/primeicons.css'
import 'sweetalert2/dist/sweetalert2.min.css'
import '@/assets/styles/swal.css'
import '@/assets/styles/native.css'
import '@/assets/styles/rtl.css'
import { initNativeMode } from '@/utils/nativeApp'
import { initAppBack } from '@/utils/appBack'
import { installAppRecovery } from '@/utils/appRecovery'
import { initServerFailover } from '@/utils/serverFailover'

// APK-only design layer: stamp <html class="native"> before the first paint so
// the shell's app-grade styling (native.css + `native:` classes) applies from
// frame one. The web app never gets the class and keeps its design untouched.
initNativeMode()

// Stamp <html lang dir> for the saved language before the first paint so an
// Arabic session never flashes left-to-right.
initLocale()

// Blank-page recovery: reload once when a deploy strands this session on dead
// chunk URLs, and toast when a view's mount-time fetch dies silently.
installAppRecovery()

// Hardware back inside the shell (v1.4.0+ asks the page): close the top
// overlay → step back toward the dashboard → double-press to leave the app.
initAppBack(router)

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(i18n)
app.use(PrimeVue, {
  ripple: true,
  locale: PRIMEVUE_LOCALES[currentLocale()],
  theme: {
    preset,
    options: {
      darkModeSelector: '[data-theme="dark"]',
      cssLayer: { name: 'primevue', order: 'tailwind-base, primevue, tailwind-utilities' },
    },
  },
})
app.directive('tooltip', Tooltip)

app.mount('#app')

// APK only: when the current server origin stops answering, hop to the office
// LAN origins (or back to app.* once the phone leaves the building) — see
// utils/serverFailover.js. Needs pinia active, hence after mount.
initServerFailover()

// Installable app (PWA) + offline boot for the Android shell: the service
// worker caches the app shell so the SPA opens with zero signal (remote-mode
// Capacitor loads the live URL — without the worker there is no app offline).
// Production builds only: the Vite dev server doesn't ship /sw.js.
//
// Kill-switch: /api/v1/app-config (public, never SW-cached — /api is in the
// worker's BYPASS list) can turn the worker off fleet-wide via APP_SW_ENABLED
// if a WebView build misbehaves — no APK re-release needed. When the check
// itself fails we are offline: keep the registered worker, that IS the feature.
if ('serviceWorker' in navigator && import.meta.env.PROD) {
  window.addEventListener('load', async () => {
    try {
      const res = await fetch('/api/v1/app-config', { cache: 'no-store' })
      const cfg = await res.json()
      if (cfg.service_worker === false) {
        const regs = await navigator.serviceWorker.getRegistrations()
        regs.forEach((reg) => reg.unregister())
        return
      }
    } catch {
      /* offline launch — leave any existing worker in place */
    }
    navigator.serviceWorker
      .register('/sw.js')
      .then((reg) => {
        // The APK's WebView lives for days without a real navigation — the
        // only moment the browser re-checks sw.js on its own. Re-check on
        // every foreground instead, so a deploy's whole-build precache lands
        // the first time the phone has signal, not days later: offline boot
        // is only as good as the last completed precache.
        document.addEventListener('visibilitychange', () => {
          if (document.visibilityState === 'visible') reg.update().catch(() => {})
        })
      })
      .catch(() => {
        // Registration failing (old browser, private mode) must never break the app.
      })
  })
}
