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
import { isNativeApp } from '@/utils/nativeApp'

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

// Installable app (PWA): register the service worker in production builds only —
// the Vite dev server doesn't ship /sw.js, and caching would fight HMR anyway.
// The Capacitor Android shell must NOT run it: SW-served navigations bypass the
// webview request layer, and the shell has its own update path (every page load
// is the live site). Unregister defensively in case one was ever registered.
if ('serviceWorker' in navigator) {
  if (isNativeApp()) {
    navigator.serviceWorker
      .getRegistrations()
      .then((regs) => regs.forEach((reg) => reg.unregister()))
      .catch(() => {})
  } else if (import.meta.env.PROD) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/sw.js').catch(() => {
        // Registration failing (old browser, private mode) must never break the app.
      })
    })
  }
}
