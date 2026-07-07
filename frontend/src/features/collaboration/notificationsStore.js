import { defineStore } from 'pinia'
import { notificationsApi } from '@/features/collaboration/api'
import { getEcho } from '@/composables/useEcho'
import { toastInfo } from '@/composables/useConfirm'
import { useChatDockStore } from '@/features/collaboration/chatDockStore'
import { playNotificationSound } from '@/utils/notificationSound'
import { cacheSnapshot, serveSnapshot } from '@/features/offline/snapshots'

// In-app notification feed backing the AppShell bell. Loads the latest page over
// HTTP and keeps the unread badge live over Reverb (the user's private channel).
// Network calls live in api.js; this store only holds state + orchestrates.
export const useNotificationsStore = defineStore('notifications', {
  state: () => ({
    items: [],
    unreadCount: 0,
    loading: false,
    loadingMore: false,
    page: 1,
    lastPage: 1,
    subscribed: false,
  }),

  getters: {
    hasMore: (state) => state.page < state.lastPage,
  },

  actions: {
    async fetch() {
      this.loading = true
      try {
        const res = await notificationsApi.list({ page: 1 })
        this.items = res.data
        this.unreadCount = res.unread_count ?? 0
        this.page = res.meta?.current_page ?? 1
        this.lastPage = res.meta?.last_page ?? 1
        cacheSnapshot('notifications:p1', {
          items: this.items,
          unreadCount: this.unreadCount,
          lastPage: this.lastPage,
        })
      } catch (e) {
        const served = await serveSnapshot(e, 'notifications:p1', (data) => {
          this.items = data.items
          this.unreadCount = data.unreadCount ?? 0
          this.page = 1
          this.lastPage = data.lastPage ?? 1
        })
        if (!served) throw e
      } finally {
        this.loading = false
      }
    },

    // Fetches the next page and appends. Guarded so a scroll handler can call
    // this freely without tracking pagination state itself.
    async loadMore() {
      if (this.loadingMore || !this.hasMore) return
      this.loadingMore = true
      try {
        const res = await notificationsApi.list({ page: this.page + 1 })
        this.items.push(...res.data)
        this.page = res.meta?.current_page ?? this.page + 1
        this.lastPage = res.meta?.last_page ?? this.lastPage
      } finally {
        this.loadingMore = false
      }
    },

    async markRead(id) {
      const item = this.items.find((n) => n.id === id)
      if (item && !item.read_at) {
        const { unread_count } = await notificationsApi.markRead(id)
        item.read_at = new Date().toISOString()
        this.unreadCount = unread_count
      }
    },

    async markUnread(id) {
      const item = this.items.find((n) => n.id === id)
      if (item && item.read_at) {
        const { unread_count } = await notificationsApi.markUnread(id)
        item.read_at = null
        this.unreadCount = unread_count
      }
    },

    async markAllRead() {
      const { unread_count } = await notificationsApi.markAllRead()
      const now = new Date().toISOString()
      this.items.forEach((n) => (n.read_at = n.read_at ?? now))
      this.unreadCount = unread_count
    },

    // Prepend a notification that arrived live over the websocket.
    pushLive(payload) {
      this.items.unshift({
        id: payload.id,
        kind: payload.kind,
        title: payload.title,
        body: payload.body,
        link: payload.link,
        subject_type: payload.subject_type,
        subject_id: payload.subject_id,
        read_at: null,
        created_at: payload.created_at ?? new Date().toISOString(),
      })
      this.unreadCount += 1
      // Chat messages get the dock treatment (head + pop sound, suppressed when
      // the thread is open); everything else chimes the bell.
      if (payload.kind === 'chat_message') {
        useChatDockStore().noteIncoming(payload)
      } else {
        playNotificationSound()
      }
      // Security alert: an account locked itself out — flash it so whoever can
      // unlock (this notification only goes to them) sees it without opening
      // the bell.
      if (payload.kind === 'account_locked') {
        toastInfo(`${payload.title} — ${payload.body}`)
      }
    },

    // Subscribe to the current user's private channel for live notifications.
    subscribe(userId) {
      if (this.subscribed || !userId) return
      const echo = getEcho()
      if (!echo) return // real-time not configured — HTTP fetch still works
      echo.private(`users.${userId}`).notification((payload) => this.pushLive(payload))
      this.subscribed = true
    },
  },
})
