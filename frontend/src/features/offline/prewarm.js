import { useAuthStore } from '@/features/settings/store'
import { useNetworkStore } from '@/features/offline/networkStore'
import { prewarmClientsList, prewarmClientFile } from '@/features/clients/clientsStore'
import { prewarmTasks } from '@/features/pipeline/tasksStore'
import { prewarmChat } from '@/features/collaboration/chatStore'
import { prewarmLocations } from '@/features/inventory/locationsStore'
import { prewarmUnits } from '@/features/inventory/unitsStore'
import { prewarmMyDay } from '@/features/pipeline/myDayOffline'

// Offline pre-warm: proactively snapshot the agent's WORKING SET on app open
// and on reconnect, so offline isn't just "screens I happened to visit" but
// "my day": agenda, clients, tasks, chat, inventory — plus the full client
// files of today's visits. Every piece reuses the exact snapshot key/shape its
// store serves back (the prewarm* twins are colocated in the store files).
//
// Deliberately quiet and cheap: fire-and-forget from AppShell during an idle
// slot, throttled, silent on every failure, skipped when the user asked the
// OS to save data. AppShell imports THIS module dynamically so none of the
// feature stores get pulled into the boot chunk.

const MIN_INTERVAL_MS = 5 * 60_000
const MAX_CLIENT_FILES = 8

let lastRunAt = 0
let running = false

export async function prewarmOfflineData() {
  const auth = useAuthStore()
  const network = useNetworkStore()
  if (running || !network.online || !auth.isAuthenticated || auth.offlineSession) return
  if (navigator.connection?.saveData) return
  if (Date.now() - lastRunAt < MIN_INTERVAL_MS) return
  lastRunAt = Date.now()
  running = true
  try {
    const results = await Promise.allSettled([
      auth.isAgent ? prewarmMyDay() : Promise.resolve(null),
      prewarmClientsList(),
      prewarmTasks(),
      prewarmChat(),
      prewarmLocations(),
      prewarmUnits(),
    ])

    // Today's visits: their client files are what a no-signal day actually
    // needs. Sequential on purpose — idle traffic, not a burst.
    const day = results[0].status === 'fulfilled' ? results[0].value : null
    const ids = [
      ...new Set(
        (day?.visits ?? [])
          .map((v) => v.link?.match(/^\/clients\/(\d+)/)?.[1])
          .filter(Boolean),
      ),
    ].slice(0, MAX_CLIENT_FILES)
    for (const id of ids) await prewarmClientFile(id)
  } finally {
    running = false
  }
}
