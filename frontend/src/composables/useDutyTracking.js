import { computed, ref } from 'vue'
import { pipelineApi } from '@/features/pipeline/api'
import { getEcho } from '@/composables/useEcho'
import { useAuthStore } from '@/features/settings/store'

// The agent-side half of live dispatch — DEMAND-DRIVEN, because continuous
// GPS eats a field phone's battery:
//
//   idle on duty   one cheap SNAPSHOT fix per interval (server-paced via
//                  `snapshot_s`, default 5 min, 0 = off) — the day replay
//                  gets a full path without a standing watcher; between
//                  snapshots there is no location access at all;
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
// the lock screen; ≥2.0 runs the same snapshot cadence) — when the native
// bridge takes over, the web side stands down completely.

const onDuty = ref(false)
const dutySince = ref(null)
const supported = typeof navigator !== 'undefined' && 'geolocation' in navigator
const geoDenied = ref(false)
const lastFixAt = ref(null)

const PRECISION_MIN_POST_MS = 20_000
const MOVE_METERS = 30
const BURST_MS = 120_000 // dispatcher looked: precision for 2 minutes
const DEFAULT_SNAPSHOT_MS = 300_000 // server-paced via snapshot_s

let precisionWatchId = null
let burstTimer = null
let snapshotTimer = null
let snapshotMs = DEFAULT_SNAPSHOT_MS
let enRoute = false // My Day's flag: a leg is being driven
let serverPrecision = false // the server's flag from the last post
let burstUntil = 0 // dispatcher-pull window
let lastSentMs = 0
let lastLat = null
let lastLng = null
let posting = false
let locateChannel = null
let locateUserId = null
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
    geoDenied.value = false // a fix went through — any earlier denial is over
    // The server knows whether an en-route leg is live — obey its verdict.
    serverPrecision = Boolean(data?.precision)
    syncPrecisionWatch()
    // The server also paces the path snapshots (settings change = no deploy).
    if (Number.isFinite(data?.snapshot_s)) applySnapshotPace(data.snapshot_s * 1000)
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

// --- Path snapshots (the full-day trail) --------------------------------------
// One cheap fix per interval so the dispatcher's replay shows the whole duty
// stretch, not just en-route legs. Interval is the server's snapshot_s
// (0 = snapshots off); the native shell runs its own twin of this loop.

function syncSnapshots() {
  const wanted = onDuty.value && supported && !nativeActive && snapshotMs > 0
  if (wanted && snapshotTimer === null) {
    grabFix({ precise: false }) // the path starts where duty starts
    snapshotTimer = setInterval(() => {
      // The precision watch is already posting fixes — don't double up.
      if (!precisionNeeded()) grabFix({ precise: false })
    }, snapshotMs)
  } else if (!wanted && snapshotTimer !== null) {
    clearInterval(snapshotTimer)
    snapshotTimer = null
  }
}

function applySnapshotPace(ms) {
  if (ms === snapshotMs) return
  snapshotMs = ms
  if (snapshotTimer !== null) {
    clearInterval(snapshotTimer)
    snapshotTimer = null
  }
  syncSnapshots()
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

// --- Dispatcher pull ----------------------------------------------------------

function onLocatePing() {
  // The shell answers the FCM twin natively — don't double-post.
  if (!onDuty.value || nativeActive) return
  burstUntil = Date.now() + BURST_MS
  grabFix({ precise: true })
  syncPrecisionWatch()
  // Nothing re-evaluates on its own anymore — close the window explicitly.
  if (burstTimer) clearTimeout(burstTimer)
  burstTimer = setTimeout(syncPrecisionWatch, BURST_MS + 1000)
}

function listenForLocate() {
  const userId = useAuthStore().user?.id
  if (!userId) return
  // Field phones are shared: a different user logged in since the last
  // subscription must not keep answering pings aimed at the previous one.
  if (locateChannel && locateUserId === userId) return
  detachLocate()
  const echo = getEcho()
  if (!echo) return
  locateChannel = echo.private(`users.${userId}`)
  locateUserId = userId
  locateChannel.listen('.duty.locate', onLocatePing)
}

function detachLocate() {
  // Only the listener: `users.{id}` carries the bell/chat streams too, so the
  // channel itself is never left from here.
  locateChannel?.stopListening('.duty.locate', onLocatePing)
  locateChannel = null
  locateUserId = null
}

function stopAll() {
  if (snapshotTimer !== null) clearInterval(snapshotTimer)
  snapshotTimer = null
  if (burstTimer) clearTimeout(burstTimer)
  burstTimer = null
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

/**
 * Full reset on logout (authStore.clear): stop every tracker — including the
 * native foreground service — drop the locate listener and forget whose duty
 * state this module mirrors, so the next login starts from a clean slate.
 */
export function teardownDutyTracking() {
  stopAll()
  detachLocate()
  onDuty.value = false
  dutySince.value = null
  lastFixAt.value = null
  geoDenied.value = false
  enRoute = false
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
    if (result === 'location-off' && onDuty.value) {
      // On duty server-side but the phone's location is off (turned off
      // while away): pull the plug through the server — it ends duty and
      // notifies the agent AND the dispatchers.
      pipelineApi.locationLost().then(apply).catch(() => {})
      return false
    }
    if (result === 'requested' && !retryArmed) {
      retryArmed = true
      window.addEventListener(
        'plaza:location-permission',
        () => {
          retryArmed = false
          if (onDuty.value && tryNativeTracking()) syncPrecisionWatch()
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
  if (Number.isFinite(state?.snapshot_s)) snapshotMs = state.snapshot_s * 1000
  if (onDuty.value) {
    listenForLocate()
    // Precision stays demand-driven (en-route leg / dispatcher's ping); the
    // only standing schedule is the cheap path snapshot every few minutes.
    tryNativeTracking()
    syncPrecisionWatch()
    syncSnapshots()
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
  // Duty with the device's location switched off would be a lie on the
  // dispatch board — refuse up front, open the settings, let the user retry.
  if (on && typeof window.PlazaNative?.isLocationEnabled === 'function') {
    try {
      if (!window.PlazaNative.isLocationEnabled()) {
        window.PlazaNative.openLocationSettings?.()
        throw new Error('location-off')
      }
    } catch (e) {
      if (e.message === 'location-off') throw e
      /* old bridge quirks: fall through, the shell watchdog covers it */
    }
  }
  apply(await pipelineApi.setDuty(on))
  return onDuty.value
}

/** My Day flips this when a visit goes en route / arrives — precision follows. */
function setEnRoute(active) {
  enRoute = Boolean(active)
  if (nativeActive) {
    // The shell holds the listeners; tell it directly (v1.8+; older shells
    // learn from the server's `precision` cue on their next post instead).
    try {
      window.PlazaNative?.setDutyPrecision?.(enRoute)
    } catch {
      /* old bridge */
    }
  }
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
