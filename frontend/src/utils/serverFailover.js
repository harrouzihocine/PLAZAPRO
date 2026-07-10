import { watch } from 'vue'
import { isNativeApp } from '@/utils/nativeApp'
import { useNetworkStore } from '@/features/offline/networkStore'

// Multi-origin failover for the Android shell (APK only — browser tabs and
// the PWA stay on whatever origin they were opened on). One backend, three
// doors, in priority order:
//
//   https://app.plaza-pro.com     internet (Cloudflare tunnel) — the default
//   https://office.plaza-pro.com  office LAN direct (public DNS → LAN IP)
//   https://192.168.1.200         office LAN with ZERO DNS — the door that
//                                 still opens when the internet (and with it
//                                 public DNS) is down. Served with a private-
//                                 CA cert only the APK trusts (see the
//                                 shell's network_security_config.xml).
//
// When the network store flags us offline, confirm the CURRENT origin is
// really down (/up), then knock on the other doors and hard-navigate to the
// first that answers. Cross-origin probes are opaque no-cors fetches — any
// resolved response proves TCP+TLS reached our server, which is all we need.
//
// The same watcher also brings phones home: a session parked on a LAN origin
// that leaves the building can no longer reach 192.168.1.200 / office.*, goes
// "offline", and fails over straight back to app.* over mobile data. Cold
// starts always begin at app.* (the shell's server.url), so nobody stays on a
// fallback origin longer than the outage itself.
//
// Sessions are per-origin cookies: after a switch the user may land on the
// login screen (docs/production-runbook.md recommends SESSION_DOMAIN=
// .plaza-pro.com so app.* ↔ office.* share theirs; the bare IP never can).
//
// Keep ORIGINS in sync with the shell's PlazaWebViewClient.java — its twin
// for the cold-boot case where no page (and no JS) could load at all.
const ORIGINS = [
  'https://app.plaza-pro.com',
  'https://office.plaza-pro.com',
  'https://192.168.1.200',
]

const PROBE_TIMEOUT_MS = 4000
const RETRY_EVERY_MS = 45_000

// Which of the three doors this page came through — 'app' | 'office' | 'ip',
// or null on an unknown origin (dev server). Drives the navbar ServerIndicator.
// Module-level const is enough — changing origin is a full page navigation.
export const serverOrigin =
  ['app', 'office', 'ip'][ORIGINS.indexOf(window.location.origin)] ?? null

// The host shown to the user next to the indicator icon.
export const serverHost = window.location.host

let lastAttemptAt = 0
let retryTimer = null

export function initServerFailover() {
  if (!import.meta.env.PROD || !isNativeApp()) return
  if (!ORIGINS.includes(window.location.origin)) return

  const network = useNetworkStore()
  watch(
    () => network.online,
    (online) => {
      if (!online) attempt(network)
    }
  )
  if (!network.online) attempt(network)
}

async function attempt(network) {
  const now = Date.now()
  if (now - lastAttemptAt < RETRY_EVERY_MS) {
    scheduleRetry(network)
    return
  }
  lastAttemptAt = now

  // The offline flag can come from one flaky request — trust a dedicated
  // probe of the current origin before uprooting the session.
  if (await reachable(window.location.origin)) {
    network.probeNow()
    return
  }

  for (const origin of ORIGINS) {
    if (origin === window.location.origin) continue
    if (await reachable(origin)) {
      const path = window.location.pathname + window.location.search + window.location.hash
      window.location.replace(origin + path)
      return
    }
  }

  // Nobody answered — genuinely offline (the SW/outbox layer takes it from
  // here). Keep re-knocking: the LAN door opening is exactly the event the
  // network store's own /api probe of the DEAD origin will never see.
  scheduleRetry(network)
}

function scheduleRetry(network) {
  if (retryTimer) return
  retryTimer = setTimeout(() => {
    retryTimer = null
    if (!network.online) attempt(network)
  }, RETRY_EVERY_MS)
}

async function reachable(origin) {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), PROBE_TIMEOUT_MS)
  try {
    if (origin === window.location.origin) {
      const res = await fetch('/up', { cache: 'no-store', signal: controller.signal })
      return res.ok
    }
    // Cross-origin: opaque response — resolving at all is the signal.
    await fetch(origin + '/up', { mode: 'no-cors', cache: 'no-store', signal: controller.signal })
    return true
  } catch {
    return false
  } finally {
    clearTimeout(timer)
  }
}
