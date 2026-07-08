import { createApp } from 'vue'
import { createPinia } from 'pinia'
import PrimeVue from 'primevue/config'
import Tooltip from 'primevue/tooltip'
import router from '@/router'
import App from '@/App.vue'
import preset from '@/theme/preset'
import '@fontsource-variable/inter'
import 'flag-icons/css/flag-icons.min.css'
import '@/assets/styles/tailwind.css'
import 'primeicons/primeicons.css'
import 'sweetalert2/dist/sweetalert2.min.css'
import '@/assets/styles/swal.css'
import '@/assets/styles/native.css'
import { initNativeMode } from '@/utils/nativeApp'
import { installAppRecovery } from '@/utils/appRecovery'

// APK-only design layer: stamp <html class="native"> before the first paint so
// the shell's app-grade styling (native.css + `native:` classes) applies from
// frame one. The web app never gets the class and keeps its design untouched.
initNativeMode()

// Blank-page recovery: reload once when a deploy strands this session on dead
// chunk URLs, and toast when a view's mount-time fetch dies silently.
installAppRecovery()

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(PrimeVue, {
  ripple: true,
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
    navigator.serviceWorker.register('/sw.js').catch(() => {
      // Registration failing (old browser, private mode) must never break the app.
    })
  })
}
