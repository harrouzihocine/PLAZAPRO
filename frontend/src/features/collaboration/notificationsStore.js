import { defineStore } from 'pinia'
import { notificationsApi } from '@/features/collaboration/api'
import { getEcho } from '@/composables/useEcho'

// In-app notification feed backing the AppShell bell. Loads the latest page over
// HTTP and keeps the unread badge live over Reverb (the user's private channel).
// Network calls live in api.js; this store only holds state + orchestrates.
export const useNotificationsStore = defineStore('notifications', {
  state: () => ({
    items: [],
    unreadCount: 0,
    loading: false,
    subscribed: false,
  }),

  actions: {
    async fetch() {
      this.loading = true
      try {
        const res = await notificationsApi.list()
        this.items = res.data
        this.unreadCount = res.unread_count ?? 0
      } finally {
        this.loading = false
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
