import { defineStore } from 'pinia'
import { getEcho } from '@/composables/useEcho'
import { toastInfo, toastSuccess } from '@/composables/useConfirm'
import { humanize } from '@/utils/format'
import { useUnitsStore } from '@/features/inventory/unitsStore'

// The app-wide live announcements rail: one public "announcements" channel that
// every logged-in browser subscribes to (once, from AppShell). Sold units fire a
// full-screen celebration; any status move repaints open unit views + a toast; a
// new unit pops a toast. Durable bell records ride the private per-user channel
// (notificationsStore) — this store is the ephemeral, everyone-sees-it layer.
export const useAnnouncementsStore = defineStore('announcements', {
  state: () => ({
    subscribed: false,
    // The sold payload while the celebration overlay is on screen (else null).
    celebration: null,
  }),

  actions: {
    subscribe() {
      if (this.subscribed) return
      const echo = getEcho()
      if (!echo) return // real-time not configured — HTTP still works

      echo
        .channel('announcements')
        .listen('.unit.sold', (payload) => this.celebrate(payload))
        .listen('.unit.status-changed', (payload) => this.applyStatus(payload))
        .listen('.unit.published', (payload) => this.newUnit(payload))
        .listen('.box.published', (payload) => this.newBox(payload))
        .listen('.inventory.updated', (payload) => this.itemUpdated(payload))

      this.subscribed = true
    },

    // A unit sold — raise the celebration for everyone and mark it sold in any
    // open list/detail so the badge flips without a refresh. It stays up until
    // the viewer dismisses it (no auto-timeout).
    celebrate(payload) {
      this.celebration = payload
      this.patchUnit(payload.unit_id, { sale_status: 'sold', reserved_count: 0 })
    },

    dismissCelebration() {
      this.celebration = null
    },

    // A status move (reserved / on hold / available / sold) — repaint open views
    // and surface a quiet toast so people notice the change live.
    applyStatus(payload) {
      this.patchUnit(payload.id, {
        sale_status: payload.sale_status,
        reserved_count: payload.reserved_count,
        onhold_expires_at: payload.onhold_expires_at,
      })
      if (payload.reference) {
        toastInfo(`${payload.reference} · ${humanize(payload.sale_status)}`)
      }
    },

    newUnit(payload) {
      toastSuccess(`New unit added: ${payload.reference}`)
    },

    newBox(payload) {
      toastSuccess(`New box added: ${payload.reference}`)
    },

    // A unit or box was edited anywhere — flash a live toast so the whole team
    // sees the change without a refresh, naming what moved when known.
    itemUpdated(payload) {
      const label = payload.type === 'box' ? 'Box' : 'Unit'
      const detail = payload.changed ? ` — ${payload.changed} changed` : ' updated'
      toastInfo(`${label} ${payload.reference}${detail}`)
    },

    // Live-patch the units store (the global table, a project's list, and the
    // open unit page all read from it) so open views update with no refresh.
    patchUnit(id, changes) {
      if (!id) return
      const units = useUnitsStore()
      const apply = (u) => {
        if (!u || u.id !== id) return
        for (const [key, value] of Object.entries(changes)) {
          if (value !== undefined) u[key] = value
        }
      }
      units.items.forEach(apply)
      apply(units.current)
    },
  },
})
