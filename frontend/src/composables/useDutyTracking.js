import { computed, ref } from 'vue'
import { pipelineApi } from '@/features/pipeline/api'
import { getEcho } from '@/composables/useEcho'
import { useAuthStore } from '@/features/settings/store'

// The agent-side half of live dispatch — DEMAND-DRIVEN, because continuous
// GPS eats a field phone's battery:
//
//   idle on duty   one cheap, low-accuracy fix every 5 minutes ("roughly
//                  where", keeps the roster's fix-age honest);
//   precision      a real high-accuracy watch, ONLY while it's needed:
//                    - a visit is en route (geofence arrival + live ETA),
//                      flagged by My Day AND confirmed by the server on every
//                      post (`precision` in the /me/positions response);
//                    - a dispatcher is actually looking: map open / roster
//                      click sends a `duty.locate` ping over the agent's own
//                      channel → one fresh fix + a short 2-minute burst.
//   off duty       nothing, ever — the server refuses strays with a 409.
//
// APK shells ≥1.6 run this contract natively (foreground service, survives
// the lock screen; ≥1.7 applies the same coarse/precision split) — when the
// native bridge takes over, the web side stands down completely.

const onDuty = ref(false)
const dutySince = ref(null)
const supported = typeof navigator !== 'undefined' && 'geolocation' in navigator
const geoDenied = ref(false)
const lastFixAt = ref(null)

const IDLE_POLL_MS = 300_000 // coarse heartbeat: one low-power fix / 5 min
const PRECISION_MIN_POST_MS = 20_000
const MOVE_METERS = 30
const BURST_MS = 120_000 // dispatcher looked: precision for 2 minutes

let idleTimer = null
let precisionWatchId = null
let enRoute = false // My Day's flag: a leg is being driven
let serverPrecision = false // the server's flag from the last post
let burstUntil = 0 // dispatcher-pull window
let lastSentMs = 0
let lastLat = null
let lastLng = null
let posting = false
let locateChannel = null
let nativeActive = false
let retryArmed = false

function precisionNeeded() {
  return enRoute || serverPrecision || Date.now() < burstUntil
}

function movedEnough(lat, lng) {
  if (lastLat === null) return true
  const dLat = ((lat - lastLat) * Math.PI) / 180
  const dLng = ((lng - lastLng) * Math.PI) / 180
  const meanLat = ((lat + lastLat) / 2) * (Math.PI / 180)
  return 6371000 * Math.hypot(dLat, dLng * Math.cos(meanLat)) >= MOVE_METERS
}

async function postFix(coords) {
  if (posting) return
  posting = true
  try {
    const data = await pipelineApi.postPosition({
      latitude: Math.round(coords.latitude * 1e7) / 1e7,
      longitude: Math.round(coords.longitude * 1e7) / 1e7,
      accuracy_m: Number.isFinite(coords.accuracy)
        ? Math.min(65000, Math.round(coords.accuracy))
        : null,
    })
    lastSentMs = Date.now()
    lastLat = coords.latitude
    lastLng = coords.longitude
    lastFixAt.value = new Date()
    // The server knows whether an en-route leg is live — obey its verdict.
    serverPrecision = Boolean(data?.precision)
    syncPrecisionWatch()
  } catch (e) {
    if (e.response?.status === 409) {
      onDuty.value = false
      stopAll()
    }
  } finally {
    posting = false
  }
}

/** One fix, cheap or precise. Failures are silently dropped (next tick retries). */
function grabFix({ precise }) {
  if (!supported) return
  navigator.geolocation.getCurrentPosition(
    (position) => postFix(position.coords),
    (err) => {
      if (err.code === err.PERMISSION_DENIED) {
        geoDenied.value = true
        stopAll()
      }
    },
    { enableHighAccuracy: precise, maximumAge: precise ? 10_000 : 120_000, timeout: 30_000 },
  )
}

// --- The precision watch (en route / dispatcher looking) ---------------------

function onPrecisionFix(position) {
  const { latitude, longitude } = position.coords
  const due = Date.now() - lastSentMs >= PRECISION_MIN_POST_MS
  if (!due && !movedEnough(latitude, longitude)) return
  postFix(position.coords)
}

function syncPrecisionWatch() {
  if (!supported || nativeActive) return
  const wanted = onDuty.value && precisionNeeded()
  if (wanted && precisionWatchId === null) {
    precisionWatchId = navigator.geolocation.watchPosition(onPrecisionFix, (err) => {
      if (err.code === err.PERMISSION_DENIED) {
        geoDenied.value = true
        stopAll()
      }
    }, { enableHighAccuracy: true, maximumAge: 10_000, timeout: 30_000 })
  } else if (!wanted && precisionWatchId !== null) {
    navigator.geolocation.clearWatch(precisionWatchId)
    precisionWatchId = null
  }
}

// --- Idle heartbeat + dispatcher pull ----------------------------------------

function startIdlePolling() {
  if (idleTimer !== null || !supported) return
  geoDenied.value = false
  grabFix({ precise: false })
  idleTimer = setInterval(() => {
    // The precision watch is already streaming — no extra fix needed.
    if (precisionWatchId === null) grabFix({ precise: false })
    if (Date.now() >= burstUntil) syncPrecisionWatch() // burst expired
  }, IDLE_POLL_MS)
}

function listenForLocate() {
  if (locateChannel) return
  const echo = getEcho()
  const userId = useAuthStore().user?.id
  if (!echo || !userId) return
  locateChannel = echo.private(`users.${userId}`)
  locateChannel.listen('.duty.locate', () => {
    // The shell answers the FCM twin natively — don't double-post.
    if (!onDuty.value || nativeActive) return
    burstUntil = Date.now() + BURST_MS
    grabFix({ precise: true })
    syncPrecisionWatch()
  })
}

function stopAll() {
  if (idleTimer !== null) clearInterval(idleTimer)
  idleTimer = null
  if (precisionWatchId !== null && supported) navigator.geolocation.clearWatch(precisionWatchId)
  precisionWatchId = null
  serverPrecision = false
  burstUntil = 0
  lastSentMs = 0
  lastLat = null
  lastLng = null
  if (nativeActive) {
    try {
      window.PlazaNative?.stopDutyTracking?.()
    } catch {
      /* nothing to stop */
    }
    nativeActive = false
  }
}

// --- Native shell hand-off ----------------------------------------------------

function tryNativeTracking() {
  const bridge = window.PlazaNative
  if (typeof bridge?.startDutyTracking !== 'function') return false
  try {
    const result = bridge.startDutyTracking()
    if (result === 'started') {
      nativeActive = true
      return true
    }
    if (result === 'requested' && !retryArmed) {
      retryArmed = true
      window.addEventListener(
        'plaza:location-permission',
        () => {
          retryArmed = false
          if (onDuty.value && tryNativeTracking()) {
            if (idleTimer !== null) clearInterval(idleTimer)
            idleTimer = null
            syncPrecisionWatch()
          }
        },
        { once: true },
      )
    }
  } catch {
    /* old or broken bridge — the web path below covers it */
  }
  return false
}

function apply(state) {
  onDuty.value = Boolean(state?.on)
  dutySince.value = state?.since ?? null
  if (onDuty.value) {
    listenForLocate()
    if (!tryNativeTracking()) startIdlePolling()
    syncPrecisionWatch()
  } else {
    stopAll()
  }
}

/** Pull the server's duty state and align the trackers (AppShell, on login). */
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

/** My Day flips this when a visit goes en route / arrives — precision follows. */
function setEnRoute(active) {
  enRoute = Boolean(active)
  syncPrecisionWatch()
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
