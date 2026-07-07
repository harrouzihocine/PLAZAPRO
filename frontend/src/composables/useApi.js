import axios from 'axios'
import { useAuthStore } from '@/features/settings/store'
import { useNetworkStore } from '@/features/offline/networkStore'

// One Axios instance for the whole app. Sanctum SPA (cookie) auth: no token
// header — the session cookie is sent with withCredentials. Components call
// api.get('/units'), never raw fetch().
const api = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  withXSRFToken: true, // echo the XSRF-TOKEN cookie as X-XSRF-TOKEN
  headers: { Accept: 'application/json' },
})

api.interceptors.response.use(
  (response) => {
    useNetworkStore().noteOnline() // any answer proves the link is up
    return response
  },
  async (error) => {
    const status = error.response?.status
    if (error.response) {
      useNetworkStore().noteOnline() // even a 4xx/5xx is a live connection
    } else if (error.code !== 'ERR_CANCELED') {
      useNetworkStore().noteOffline() // no response at all → offline signal
    }
    if (status === 401) {
      useAuthStore().clear() // session expired → clear local auth state
    }
    if (status === 419 && !error.config?._csrfRetried) {
      // CSRF token mismatch → refresh the cookie and retry ONCE. The flag stops
      // a still-failing replay from looping (refresh → 419 → refresh → …).
      error.config._csrfRetried = true
      await getCsrf()
      return api(error.config)
    }
    return Promise.reject(error)
  },
)

// Call before the first stateful request (e.g. before login).
export function getCsrf() {
  return axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

export function useApi() {
  return api
}
