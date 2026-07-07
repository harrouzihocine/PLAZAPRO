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
    /PlazaProNative/i.test(navigator.userAgent) || window.Capacitor?.isNativePlatform?.() === true
  )
}
