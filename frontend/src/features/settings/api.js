import { useApi, getCsrf } from '@/composables/useApi'

// Network calls for the Settings feature (auth). State lives in store.js; these
// functions are the only place Settings talks to the API (via the shared useApi).
export const authApi = {
  async login(email, password) {
    await getCsrf() // prime the CSRF cookie before the first stateful request
    const { data } = await useApi().post('/auth/login', { email, password })
    return data.data
  },

  async me() {
    const { data } = await useApi().get('/auth/me')
    return data.data
  },

  logout() {
    return useApi().post('/auth/logout')
  },
}
