import router from '@/router'
import { useApi } from '@/composables/useApi'
import { isNativeApp } from '@/utils/nativeApp'

// System-tray push glue for the Android shell. The shell binds
// `window.PlazaNative` (addJavascriptInterface — CSP-proof where the remote
// Capacitor bridge is not) with three calls:
//   getPushToken()       → the device's FCM token ('' until issued)
//   consumePendingLink() → the deep link of a tapped notification, once
//   isPushSupported()    → build carries Firebase config
//
// Flow: initNativePush runs when the authenticated shell mounts — it registers
// the device token (POST /device-tokens claims the device for THIS user) and
// routes any pending notification tap. The shell fires `plaza:push-token`
// when Firebase (re)issues a token and `plaza:push-open` when a notification
// is tapped while the app is already running.

let wired = false

export function initNativePush() {
  if (!isNativeApp() || !window.PlazaNative) return

  // Re-registering on every shell mount is deliberate: a login by a different
  // user on the same device must take the token over.
  registerToken()
  consumePendingLink()

  if (wired) return
  wired = true
  window.addEventListener('plaza:push-token', registerToken)
  window.addEventListener('plaza:push-open', consumePendingLink)
}

// On logout, release the device so the next user (or nobody) gets its pushes.
// Must run BEFORE the session dies — the endpoint is authenticated.
export async function forgetPushToken() {
  const token = window.PlazaNative?.getPushToken?.()
  if (!token) return
  try {
    await useApi().post('/device-tokens/forget', { token })
  } catch {
    // Best-effort: the backend also prunes tokens FCM reports dead.
  }
}

async function registerToken() {
  const token = window.PlazaNative?.getPushToken?.()
  if (!token) return
  try {
    await useApi().post('/device-tokens', { token, platform: 'android' })
  } catch {
    // Offline or transient — the next app start retries.
  }
}

function consumePendingLink() {
  const link = window.PlazaNative?.consumePendingLink?.()
  if (link && link.startsWith('/')) router.push(link)
}
