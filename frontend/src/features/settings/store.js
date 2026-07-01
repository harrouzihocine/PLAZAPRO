import { defineStore } from 'pinia'
import { authApi } from '@/features/settings/api'

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
    },

    clear() {
      this.user = null
      this.permissions = []
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
