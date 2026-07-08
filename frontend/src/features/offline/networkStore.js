import { defineStore } from 'pinia'
import { toastError } from '@/composables/useConfirm'
import { t } from '@/i18n'

// Single source of truth for "are we online?". navigator.onLine is unreliable
// (LAN without internet, WebView quirks) so it is never trusted directly:
//   - every axios response marks us online; every axios NETWORK error (no
//     response) marks us offline (hooks in useApi.js);
//   - window online/offline events only trigger an immediate probe;
//   - while offline, /api/v1/ping is probed with 5s → 60s backoff.
// Reconnection consumers (session revalidation, outbox replay) watch `online`.
const PROBE_DELAYS = [5000, 10000, 30000, 60000]

export const useNetworkStore = defineStore('network', {
  state: () => ({
    online: true,
    lastChangedAt: null,
    _probeStep: 0,
    _probeTimer: null,
    _initialised: false,
  }),

  actions: {
    init() {
      if (this._initialised) return
      this._initialised = true
      // Browser events are hints only — verify with a real request.
      window.addEventListener('online', () => this.probeNow())
      window.addEventListener('offline', () => this.probeNow())
      // Waking the app (tab focus, phone unlock) re-checks a stale offline flag.
      document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && !this.online) this.probeNow()
      })
    },

    noteOnline() {
      if (this._probeTimer) {
        clearTimeout(this._probeTimer)
        this._probeTimer = null
      }
      this._probeStep = 0
      if (this.online) return
      this.online = true
      this.lastChangedAt = Date.now()
    },

    noteOffline() {
      if (!this.online) {
        this._scheduleProbe()
        return
      }
      this.online = false
      this.lastChangedAt = Date.now()
      this._probeStep = 0
      this._scheduleProbe()
    },

    _scheduleProbe() {
      if (this._probeTimer) return
      const delay = PROBE_DELAYS[Math.min(this._probeStep, PROBE_DELAYS.length - 1)]
      this._probeTimer = setTimeout(() => {
        this._probeTimer = null
        this._probeStep++
        this.probeNow()
      }, delay)
    },

    async probeNow() {
      try {
        const res = await fetch('/api/v1/ping', { cache: 'no-store' })
        if (res.ok) this.noteOnline()
        else this.noteOffline()
      } catch {
        this.noteOffline()
      }
    },

    // Guard for actions that must not queue (payments, client creation, admin).
    requireOnline(message = null) {
      if (this.online) return true
      toastError(message ?? t('offline.actionNeedsConnection'))
      return false
    },
  },
})
