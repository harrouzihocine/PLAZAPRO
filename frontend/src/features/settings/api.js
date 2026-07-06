import { useApi, getCsrf } from '@/composables/useApi'

// Network calls for the Settings feature (auth). State lives in store.js; these
// functions are the only place Settings talks to the API (via the shared useApi).
export const authApi = {
  // `login` is either the username or the email — the API accepts both.
  async login(login, password) {
    await getCsrf() // prime the CSRF cookie before the first stateful request
    const { data } = await useApi().post('/auth/login', { login, password })
    return data.data
  },

  async me() {
    const { data } = await useApi().get('/auth/me')
    return data.data
  },

  logout() {
    return useApi().post('/auth/logout')
  },

  // Self-service profile (own account only): details/password, then avatar.
  async updateProfile(payload) {
    const { data } = await useApi().put('/me/profile', payload)
    return data.data
  },

  async uploadAvatar(file) {
    const form = new FormData()
    form.append('avatar', file)
    const { data } = await useApi().post('/me/avatar', form)
    return data.data
  },

  async removeAvatar() {
    const { data } = await useApi().delete('/me/avatar')
    return data.data
  },
}

// Scalar app-wide settings (e.g. the reservation hold duration in hours).
// Read is open to any authed user; writes require settings.manage.
export const appSettingsApi = {
  async get() {
    const { data } = await useApi().get('/app-settings')
    return data.data
  },

  async save(payload) {
    const { data } = await useApi().put('/app-settings', payload)
    return data.data
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

// Wilayas & communes admin (the geographic hierarchy, requires settings.manage).
// Reads (list/communes) are open to any authed user and also feed the dropdowns
// via the useGeography composable; these cover the management screen.
export const geographyApi = {
  async wilayas(params = {}) {
    const { data } = await useApi().get('/wilayas', { params })
    return data.data
  },

  async communes(wilayaId, params = {}) {
    const { data } = await useApi().get(`/wilayas/${wilayaId}/communes`, { params })
    return data.data
  },

  async createWilaya(payload) {
    const { data } = await useApi().post('/wilayas', payload)
    return data.data
  },

  async updateWilaya(id, payload) {
    const { data } = await useApi().put(`/wilayas/${id}`, payload)
    return data.data
  },

  cancelWilaya(id) {
    return useApi().delete(`/wilayas/${id}`)
  },

  async createCommune(wilayaId, payload) {
    const { data } = await useApi().post(`/wilayas/${wilayaId}/communes`, payload)
    return data.data
  },

  async updateCommune(id, payload) {
    const { data } = await useApi().put(`/communes/${id}`, payload)
    return data.data
  },

  cancelCommune(id) {
    return useApi().delete(`/communes/${id}`)
  },
}

// Departments admin. `list` is also usable anywhere a department picker is needed.
export const departmentsApi = {
  async list() {
    const { data } = await useApi().get('/departments')
    return data.data
  },

  async create(payload) {
    const { data } = await useApi().post('/departments', payload)
    return data.data
  },

  async update(id, payload) {
    const { data } = await useApi().put(`/departments/${id}`, payload)
    return data.data
  },

  cancel(id) {
    return useApi().delete(`/departments/${id}`)
  },
}

// Roles admin. `list` also feeds role/agent pickers elsewhere.
export const rolesApi = {
  async list() {
    const { data } = await useApi().get('/roles')
    return data.data
  },

  async create(payload) {
    const { data } = await useApi().post('/roles', payload)
    return data.data
  },

  async update(id, payload) {
    const { data } = await useApi().put(`/roles/${id}`, payload)
    return data.data
  },

  cancel(id) {
    return useApi().delete(`/roles/${id}`)
  },
}

// Read-only permission catalogue for the role matrix (requires roles.manage).
export const permissionsApi = {
  async list() {
    const { data } = await useApi().get('/permissions')
    return data.data
  },
}

// Users admin (requires users.manage). Users are created with exactly one role,
// then deactivated or cancelled — never deleted.
export const usersApi = {
  async list(params = {}) {
    const { data } = await useApi().get('/users', { params })
    return data.data
  },

  async create(payload) {
    const { data } = await useApi().post('/users', payload)
    return data.data
  },

  async update(id, payload) {
    const { data } = await useApi().put(`/users/${id}`, payload)
    return data.data
  },

  setActive(id, isActive) {
    return useApi().put(`/users/${id}/active`, { is_active: isActive })
  },

  cancel(id) {
    return useApi().delete(`/users/${id}`)
  },
}
