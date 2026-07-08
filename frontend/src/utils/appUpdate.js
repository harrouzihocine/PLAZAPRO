import { ref } from 'vue'
import { isNativeApp } from '@/utils/nativeApp'

// "A newer APK is published" detector for the Android shell (sideloaded apps
// have no Play Store to do this). Compares the installed build against
// /downloads/version.json — the file scripts/build-android.sh publishes next
// to the APK (served no-store, so it flips the moment a release is copied up).
//
// The installed build comes from PlazaNative.getAppVersion() (v1.2.0+). Shells
// too old to have that method are by definition on the last release without it
// (code 3, v1.1.1) — so the fleet that predates this code still gets the
// banner the moment anything newer is published.
const LEGACY_VERSION_CODE = 3
const DISMISSED_KEY = 'plaza-update-dismissed'
const CHECK_EVERY_MS = 60 * 60 * 1000 // re-check at most hourly on foreground

export const updateAvailable = ref(false)
export const latestVersionName = ref('')

let wired = false
let lastCheckAt = 0

export function initAppUpdateCheck() {
  if (!isNativeApp()) return

  check()

  if (wired) return
  wired = true
  // Phones background the app for days — re-check when it comes back, not on
  // a timer that would never fire while asleep anyway.
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') check()
  })
}

// Hide the banner for THIS release only: it comes back for the next one.
export function dismissUpdate(code) {
  localStorage.setItem(DISMISSED_KEY, String(code))
  updateAvailable.value = false
}

function installedVersionCode() {
  try {
    const raw = window.PlazaNative?.getAppVersion?.()
    const code = raw ? JSON.parse(raw)?.versionCode : null
    if (Number.isInteger(code)) return code
  } catch {
    /* malformed bridge answer — treat as legacy below */
  }
  return LEGACY_VERSION_CODE
}

let latestCode = null

export function latestVersionCode() {
  return latestCode
}

async function check() {
  const now = Date.now()
  if (now - lastCheckAt < CHECK_EVERY_MS) return
  lastCheckAt = now

  try {
    const res = await fetch('/downloads/version.json', { cache: 'no-store' })
    if (!res.ok) return
    const meta = await res.json()
    if (!Number.isInteger(meta?.versionCode)) return

    latestCode = meta.versionCode
    latestVersionName.value = meta.versionName || ''

    const dismissed = Number(localStorage.getItem(DISMISSED_KEY) || 0)
    updateAvailable.value =
      meta.versionCode > installedVersionCode() && meta.versionCode !== dismissed
  } catch {
    // Offline or version.json not published (dev) — silently no banner.
  }
}
