// Are we running inside the Capacitor Android shell (the sideloaded APK)?
//
// The shell appends "PlazaProNative/1" to the webview user agent
// (frontend/capacitor.config.json appendUserAgent). Detect via the UA first:
// the site's strict CSP (script-src 'self') can block Capacitor's inline
// bridge injection in remote mode, so window.Capacitor may be absent even
// inside the app. The UA marker survives that; the bridge check is a fallback
// for a future bundled-assets build where the UA could differ.
export function isNativeApp() {
  return (
    /PlazaProNative/i.test(navigator.userAgent) ||
    window.Capacitor?.isNativePlatform?.() === true ||
    devPreviewEnabled()
  )
}

// Dev-only preview of the native design in a normal browser: visit ?native=1
// once (persists in localStorage, ?native=0 turns it off). Never active in
// production builds — real devices are the source of truth there.
const DEV_PREVIEW_KEY = 'plaza-native-preview'

function devPreviewEnabled() {
  if (!import.meta.env.DEV) return false
  const flag = new URLSearchParams(window.location.search).get('native')
  if (flag === '1') localStorage.setItem(DEV_PREVIEW_KEY, '1')
  if (flag === '0') localStorage.removeItem(DEV_PREVIEW_KEY)
  return localStorage.getItem(DEV_PREVIEW_KEY) === '1'
}

// Stamp <html class="native"> so CSS (assets/styles/native.css) and the
// Tailwind `native:` variant can restyle the app for the APK without touching
// the web design. Call before mounting the app so the first paint is right.
export function initNativeMode() {
  document.documentElement.classList.toggle('native', isNativeApp())
}
