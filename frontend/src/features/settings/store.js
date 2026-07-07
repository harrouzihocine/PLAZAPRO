import { defineStore } from 'pinia'
import { authApi } from '@/features/settings/api'
import { useDraftsStore } from '@/features/drafts/draftsStore'

// Authentication + current-user state (Sanctum SPA cookie mode). Holds no token.
// Network calls live in api.js; this store only holds state and orchestrates them.
//
// Offline boot: the last confirmed session is snapshotted to localStorage so a
// cold start with no signal (APK, cached shell) can hydrate who's logged in and
// their permissions. Only a NETWORK failure restores it — a real 401/419 still
// clears everything, snapshot included. Revalidated on reconnect (AppShell).
const SESSION_SNAPSHOT_KEY = 'plaza:session'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    permissions: [],
    ready: false, // true once we've attempted to resolve the session at least once
    // True when the current user came from the offline snapshot, not the server.
    offlineSession: false,
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
      this.offlineSession = false
      // Drafts are per-user (they carry client PII) — load this user's set.
      if (user?.id) useDraftsStore().hydrate(user.id)
      // Refresh the offline-boot snapshot with the server-confirmed session.
      try {
        localStorage.setItem(
          SESSION_SNAPSHOT_KEY,
          JSON.stringify({ user, savedAt: new Date().toISOString() }),
        )
      } catch {
        /* storage full/blocked — offline boot simply won't have a session */
      }
    },

    clear() {
      const leavingUserId = this.user?.id
      this.user = null
      this.permissions = []
      this.offlineSession = false
      localStorage.removeItem(SESSION_SNAPSHOT_KEY)
      // Never show one user's data to the next one on this browser: drafts
      // reset, and the offline read-cache for that user is wiped.
      useDraftsStore().reset()
      if (leavingUserId) {
        import('@/features/offline/snapshots').then(({ clearUserSnapshots }) =>
          clearUserSnapshots(leavingUserId),
        )
      }
    },

    async login(login, password) {
      this.setUser(await authApi.login(login, password))
      return this.user
    },

    // Self-service profile edits. Each returns the refreshed user (with role +
    // permissions) so the header, avatar and any `can()` checks stay in sync.
    async updateProfile(payload) {
      this.setUser(await authApi.updateProfile(payload))
      return this.user
    },

    async uploadAvatar(file) {
      this.setUser(await authApi.uploadAvatar(file))
      return this.user
    },

    async removeAvatar() {
      this.setUser(await authApi.removeAvatar())
      return this.user
    },

    async fetchMe() {
      try {
        this.setUser(await authApi.me())
      } catch (e) {
        // The server ANSWERED (401 &co) → the session is truly gone. No answer
        // at all → we're offline: hydrate the snapshot so the cached shell
        // boots signed-in; the reconnect watcher revalidates against the server.
        if (e?.response) {
          this.clear()
        } else {
          const user = this._snapshotUser()
          if (user) {
            this.user = user
            this.permissions = user.permissions ?? []
            this.offlineSession = true
            if (user.id) useDraftsStore().hydrate(user.id)
          } else {
            this.clear()
          }
        }
      } finally {
        this.ready = true
      }
      return this.user
    },

    _snapshotUser() {
      try {
        return JSON.parse(localStorage.getItem(SESSION_SNAPSHOT_KEY) ?? 'null')?.user ?? null
      } catch {
        return null
      }
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
