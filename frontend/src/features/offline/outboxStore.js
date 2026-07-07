import { defineStore } from 'pinia'
import { toRaw } from 'vue'
import { idb } from '@/features/offline/idb'
import { useNetworkStore } from '@/features/offline/networkStore'
import { useApi } from '@/composables/useApi'
import { toastError, toastSuccess } from '@/composables/useConfirm'
import { hasRefreshHandler, runRefresh } from '@/composables/useRefreshRegistry'
import router from '@/router'

// The offline outbox: writes queued while disconnected, persisted in
// IndexedDB (they survive an app kill), replayed strict-FIFO on reconnect.
// Every replay carries X-Idempotency-Key = the record's uuid, so a response
// lost mid-flight can never double-apply server-side.
//
// Outcome grammar (the user's conflict scenario lives here):
//   2xx            → done, removed; the active view refreshes.
//   422/403/404/409 → TERMINAL. The server's guards said the action is no
//                     longer available ("this visit is already completed",
//                     "project frozen", …) — the item turns `failed` and shows
//                     in the Sync Center with the server's own reason.
//   network error  → still offline; stop, keep order, retry on next signal.
//   401            → session expired; pause the whole queue, resume on login.
export const useOutboxStore = defineStore('outbox', {
  state: () => ({
    items: [],
    syncing: false,
    paused: null, // 'auth' while waiting for a re-login
    _userId: null,
  }),

  getters: {
    pendingCount: (s) => s.items.filter((i) => i.status !== 'failed').length,
    failed: (s) => s.items.filter((i) => i.status === 'failed'),
    // Sync Center list: human-labelled entries (silent read-marks hidden).
    visible: (s) =>
      [...s.items.filter((i) => !i.silent)].sort((a, b) => a.createdAt.localeCompare(b.createdAt)),
    badge() {
      return this.visible.length
    },
  },

  actions: {
    // Load this user's queue (called on login / app boot). `syncing` leftovers
    // from a killed app were never confirmed — they retry as pending (the
    // idempotency key makes the retry safe even if the server applied them).
    async load(userId) {
      this._userId = userId ?? null
      if (!userId) {
        this.items = []
        return
      }
      try {
        const all = await idb.getAll('outbox')
        this.items = all
          .filter((i) => i.userId === userId)
          .sort((a, b) => a.createdAt.localeCompare(b.createdAt))
          .map((i) => ({ ...i, status: i.status === 'syncing' ? 'pending' : i.status }))
      } catch {
        this.items = []
      }
    },

    async enqueue({ uuid, method, url, body = null, files = [], label, entityHint = {}, silent = false }) {
      const record = {
        uuid: uuid ?? globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random()}`,
        userId: this._userId,
        method,
        url,
        body,
        files, // [{ field, name, type, blob }] — Blobs persist fine in IDB
        label: label ?? 'Offline change',
        entityHint,
        silent,
        createdAt: new Date().toISOString(),
        status: 'pending',
        attempts: 0,
        lastError: null,
      }
      await idb.put('outbox', record).catch(() => {})
      this.items.push(record)
      return record
    },

    async _persist(item) {
      // IDB can't clone Vue proxies — store the raw record.
      const raw = { ...toRaw(item), files: toRaw(item.files)?.map((f) => ({ ...toRaw(f) })) ?? [] }
      await idb.put('outbox', raw).catch(() => {})
    },

    async _remove(uuid) {
      await idb.del('outbox', uuid).catch(() => {})
      this.items = this.items.filter((x) => x.uuid !== uuid)
    },

    // Strict-FIFO replay. Triggered by the reconnect watcher (AppShell), after
    // a re-login, and manually from the Sync Center.
    async sync() {
      const network = useNetworkStore()
      if (this.syncing || !network.online || this.paused === 'auth') return
      this.syncing = true
      const api = useApi()
      let applied = 0
      let failedNow = 0
      try {
        const queue = this.items
          .filter((i) => i.status === 'pending')
          .sort((a, b) => a.createdAt.localeCompare(b.createdAt))
        for (const item of queue) {
          item.status = 'syncing'
          try {
            const { data } = await api.request({
              method: item.method,
              url: item.url,
              data: this._payloadOf(item),
              headers: { 'X-Idempotency-Key': item.uuid },
            })
            await this._remove(item.uuid)
            this._applySuccess(item, data)
            applied++
          } catch (e) {
            if (!e.response) {
              // Connection dropped again — stop here, keep FIFO order intact.
              item.status = 'pending'
              await this._persist(item)
              break
            }
            if (e.response.status === 401) {
              item.status = 'pending'
              await this._persist(item)
              this.paused = 'auth' // resumed by the login watcher (AppShell)
              toastError('Your session expired — sign in again to finish syncing.')
              break
            }
            // The server judged it: the action is no longer available (or was
            // rejected). Terminal — surfaced with the server's own words.
            item.attempts++
            item.status = 'failed'
            item.lastError = {
              status: e.response.status,
              message: e.response.data?.message ?? 'Rejected by the server.',
            }
            await this._persist(item)
            this._noteFailure(item)
            failedNow++
          }
        }
      } finally {
        this.syncing = false
      }

      if (applied) {
        toastSuccess(`${applied} offline change${applied > 1 ? 's' : ''} synced.`)
        // Refresh what the user is looking at — only views that registered a
        // handler (never the full-reload fallback mid-session).
        const routeName = router.currentRoute.value?.name
        if (routeName && hasRefreshHandler(routeName)) runRefresh(routeName)
      }
      if (failedNow) {
        toastError(
          failedNow === 1
            ? 'One offline change could not be applied — open Sync to see why.'
            : `${failedNow} offline changes could not be applied — open Sync to see why.`,
        )
      }
    },

    _payloadOf(item) {
      if (!item.files?.length) return item.body
      const form = new FormData()
      for (const [k, v] of Object.entries(item.body ?? {})) {
        if (v !== null && v !== undefined) form.append(k, String(v))
      }
      for (const f of item.files) {
        form.append(f.field, new File([f.blob], f.name || 'attachment', { type: f.type || '' }))
      }
      return form
    },

    // A queued chat message that landed: swap its pending clock bubble.
    async _applySuccess(item, data) {
      const h = item.entityHint ?? {}
      if (h.clientKey && h.conversationId) {
        const { useChatStore } = await import('@/features/collaboration/chatStore')
        useChatStore()._resolvePending(h.conversationId, h.clientKey, data?.data ?? data)
      }
    },

    async _noteFailure(item) {
      const h = item.entityHint ?? {}
      if (h.clientKey && h.conversationId) {
        const { useChatStore } = await import('@/features/collaboration/chatStore')
        const m = useChatStore()
          .thread(h.conversationId)
          .messages.find((x) => x.client_key === h.clientKey)
        if (m) {
          m.pending = false
          m.failed = true
        }
      }
    },

    async retry(uuid) {
      const item = this.items.find((x) => x.uuid === uuid)
      if (!item) return
      item.status = 'pending'
      item.lastError = null
      await this._persist(item)
      return this.sync()
    },

    async discard(uuid) {
      const item = this.items.find((x) => x.uuid === uuid)
      await this._remove(uuid)
      const h = item?.entityHint ?? {}
      if (h.clientKey && h.conversationId) {
        const { useChatStore } = await import('@/features/collaboration/chatStore')
        useChatStore().discardPending(h.conversationId, h.clientKey)
      }
    },

    resumeAfterLogin() {
      if (this.paused === 'auth') {
        this.paused = null
        this.sync()
      }
    },
  },
})
