import { defineStore } from 'pinia'
import { authApi } from '@/features/settings/api'
import { useDraftsStore } from '@/features/drafts/draftsStore'

// Authentication + current-user state (Sanctum SPA cookie mode). Holds no token.
// Network calls live in api.js; this store only holds state and orchestrates them.
export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    permissions: [],
    ready: false, // true once we've attempted to resolve the session at least once
  }),

  getters: {
    isAuthenticated: (state) => state.user !== null,
    isAgent: (state) => Boolean(state.user?.is_agent),
    can: (state) => (permission) => state.permissions.includes(permission),
  },

  actions: {
    setUser(user) {
      this.user = user
      this.permissions = user?.permissions ?? []
      // Drafts are per-user (they carry client PII) — load this user's set.
      if (user?.id) useDraftsStore().hydrate(user.id)
    },

    clear() {
      this.user = null
      this.permissions = []
      // Never show one user's drafts to the next one on this browser.
      useDraftsStore().reset()
    },

    async login(email, password) {
      this.setUser(await authApi.login(email, password))
      return this.user
    },

    async fetchMe() {
      try {
        this.setUser(await authApi.me())
      } catch {
        this.clear()
      } finally {
        this.ready = true
      }
      return this.user
    },

    async logout() {
      try {
        await authApi.logout()
      } finally {
        this.clear()
      }
    },
  },
})
