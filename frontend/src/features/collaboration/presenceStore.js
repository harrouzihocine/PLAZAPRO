import { defineStore } from 'pinia'
import { getEcho } from '@/composables/useEcho'

// Who is online right now, via the `online` presence channel (Reverb). Every
// logged-in client joins (AppShell) so app users and web users see each other
// as active; only the Android shell renders the green dots. Real-time being
// unavailable simply means nobody shows online — never an error.
export const usePresenceStore = defineStore('presence', {
  state: () => ({
    onlineIds: new Set(),
    joined: false,
  }),

  getters: {
    isOnline: (state) => (userId) => state.onlineIds.has(userId),
  },

  actions: {
    join() {
      if (this.joined) return
      const echo = getEcho()
      if (!echo) return
      echo
        .join('online')
        .here((users) => {
          this.onlineIds = new Set(users.map((u) => u.id))
        })
        .joining((user) => {
          this.onlineIds = new Set([...this.onlineIds, user.id])
        })
        .leaving((user) => {
          const next = new Set(this.onlineIds)
          next.delete(user.id)
          this.onlineIds = next
        })
      this.joined = true
    },
  },
})
