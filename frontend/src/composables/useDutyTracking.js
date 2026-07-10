import { computed, ref } from 'vue'
import { pipelineApi } from '@/features/pipeline/api'

// The agent-side half of live dispatch: while ON DUTY, watch the device's
// position and post throttled fixes; going off duty stops the watch dead
// (and the server refuses strays with a 409 — the privacy contract).
//
// One module-level singleton: AppShell calls refresh() once for agents so the
// watch survives route changes, and My Day binds its toggle to the same state.
//
// Battery discipline: a fix is posted when EITHER enough time passed (90 s
// idle, 20 s with a visit en route) OR the device moved ~30 m — otherwise the
// callback is ignored. watchPosition itself is cheap; the network chatter is
// what we throttle. Foreground-only by nature (a WebView/browser suspends in
// the background) — the APK shell keeps the screen alive enough in practice,
// and a native background-geolocation plugin can upgrade this later without
// touching the server contract.

const onDuty = ref(false)
const dutySince = ref(null)
const supported = typeof navigator !== 'undefined' && 'geolocation' in navigator
const geoDenied = ref(false)
const lastFixAt = ref(null)

let watchId = null
let enRoute = false
let lastSentMs = 0
let lastLat = null
let lastLng = null
let posting = false

const IDLE_MS = 90_000
const EN_ROUTE_MS = 20_000
const MOVE_METERS = 30

function movedEnough(lat, lng) {
  if (lastLat === null) return true
  // Equirectangular approximation — plenty for a 30 m threshold.
  const dLat = ((lat - lastLat) * Math.PI) / 180
  const dLng = ((lng - lastLng) * Math.PI) / 180
  const meanLat = ((lat + lastLat) / 2) * (Math.PI / 180)
  const meters = 6371000 * Math.hypot(dLat, dLng * Math.cos(meanLat))
  return meters >= MOVE_METERS
}

async function onFix(position) {
  const { latitude, longitude, accuracy } = position.coords
  const now = Date.now()
  const due = now - lastSentMs >= (enRoute ? EN_ROUTE_MS : IDLE_MS)
  if (posting || (!due && !movedEnough(latitude, longitude))) return

  posting = true
  try {
    await pipelineApi.postPosition({
      latitude: Math.round(latitude * 1e7) / 1e7,
      longitude: Math.round(longitude * 1e7) / 1e7,
      accuracy_m: Number.isFinite(accuracy) ? Math.min(65000, Math.round(accuracy)) : null,
    })
    lastSentMs = now
    lastLat = latitude
    lastLng = longitude
    lastFixAt.value = new Date()
  } catch (e) {
    if (e.response?.status === 409) {
      // The server says off duty (toggled elsewhere / session swept) — obey.
      onDuty.value = false
      stopWatch()
    }
    // Other failures (offline, timeouts): drop the fix, the next one retries.
  } finally {
    posting = false
  }
}

function startWatch() {
  if (!supported || watchId !== null) return
  geoDenied.value = false
  watchId = navigator.geolocation.watchPosition(onFix, (err) => {
    if (err.code === err.PERMISSION_DENIED) {
      geoDenied.value = true
      stopWatch()
    }
  }, {
    enableHighAccuracy: true,
    maximumAge: 15_000,
    timeout: 30_000,
  })
}

function stopWatch() {
  if (watchId !== null && supported) navigator.geolocation.clearWatch(watchId)
  watchId = null
  lastSentMs = 0
  lastLat = null
  lastLng = null
}

function apply(state) {
  onDuty.value = Boolean(state?.on)
  dutySince.value = state?.since ?? null
  if (onDuty.value) startWatch()
  else stopWatch()
}

/** Pull the server's duty state and align the watcher (AppShell, on login). */
async function refresh() {
  try {
    apply(await pipelineApi.dutyState())
  } catch {
    /* signed out or offline — the next refresh realigns */
  }
}

/** The My Day toggle. Returns the applied state. */
async function setDuty(on) {
  apply(await pipelineApi.setDuty(on))
  return onDuty.value
}

/** Tighter cadence while a visit is en route (My Day flips this). */
function setEnRoute(active) {
  enRoute = Boolean(active)
}

export function useDutyTracking() {
  return {
    onDuty: computed(() => onDuty.value),
    dutySince: computed(() => dutySince.value),
    lastFixAt: computed(() => lastFixAt.value),
    geoDenied: computed(() => geoDenied.value),
    supported,
    refresh,
    setDuty,
    setEnRoute,
  }
}
