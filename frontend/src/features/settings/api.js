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

// Dynamic-lists admin (the dropdown backbone). Read of a single list is done via
// the useDynamicList composable; these cover the management screen.
export const dynamicListsApi = {
  async list() {
    const { data } = await useApi().get('/dynamic-lists')
    return data.data
  },

  async items(key) {
    const { data } = await useApi().get(`/dynamic-lists/${key}/items`)
    return data.data
  },

  async createItem(key, payload) {
    const { data } = await useApi().post(`/dynamic-lists/${key}/items`, payload)
    return data.data
  },

  async updateItem(key, id, payload) {
    const { data } = await useApi().put(`/dynamic-lists/${key}/items/${id}`, payload)
    return data.data
  },

  deactivateItem(key, id) {
    return useApi().delete(`/dynamic-lists/${key}/items/${id}`)
  },

  reorder(key, order) {
    return useApi().post(`/dynamic-lists/${key}/items/reorder`, { order })
  },
}
