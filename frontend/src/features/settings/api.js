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

  // Which notification categories reach the phone as system-tray push.
  // Partial maps are fine — the backend merges over what's saved.
  async updatePushPrefs(prefs) {
    const { data } = await useApi().put('/me/push-prefs', prefs)
    return data.data
  },

  // UI language (en/fr/ar) — saved on the profile so backend-built text
  // (validation, notifications, push, digest) speaks the user's language.
  async updateLocale(locale) {
    const { data } = await useApi().put('/me/locale', { locale })
    return data.data
  },

  // Connected sessions: every device this account is signed in on. Sessions
  // are addressed by sha256 hash — the raw id never leaves the server.
  async sessions() {
    const { data } = await useApi().get('/me/sessions')
    return data.data
  },

  endSession(id) {
    return useApi().delete(`/me/sessions/${id}`)
  },

  async endOtherSessions() {
    const { data } = await useApi().delete('/me/other-sessions')
    return data.data
  },
}

// Scalar app-wide settings (e.g. the interest hold duration in hours).
// Read is open to any authed user; writes require settings.manage.
let appSettingsOnce = null

export const appSettingsApi = {
  async get() {
    const { data } = await useApi().get('/app-settings')
    return data.data
  },

  // One fetch per app session — the values move rarely and several forms read
  // them on mount. A failed fetch is not kept, so the next mount retries; a
  // save drops the memo so fresh values win without a reload.
  cached() {
    appSettingsOnce ??= this.get().catch((e) => {
      appSettingsOnce = null
      throw e
    })
    return appSettingsOnce
  },

  async save(payload) {
    const { data } = await useApi().put('/app-settings', payload)
    appSettingsOnce = null
    return data.data
  },
}

// The public site's hero library anchor (Settings → Website drives the
// standard media endpoints with mediableType 'website' + this id).
export const websiteSpaceApi = {
  async get() {
    const { data } = await useApi().get('/website-space')
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

  // Clear a brute-force login lock (3 failed passwords by default) so the
  // user can sign in again.
  unlock(id) {
    return useApi().put(`/users/${id}/unlock`)
  },

  cancel(id) {
    return useApi().delete(`/users/${id}`)
  },

  // Offboarding desk (users.transfer): everything the user owns — their
  // career record plus the open work that would orphan when they leave.
  // Pass { totals: 1 } for the cheap probe (open-book counts only).
  async workload(id, params = {}) {
    const { data } = await useApi().get(`/users/${id}/workload`, { params })
    return data.data
  },

  // Hand the open work to a successor ({ successor_id, dispatch_to_pool }).
  async transferWork(id, payload) {
    const { data } = await useApi().post(`/users/${id}/transfer-work`, payload)
    return data.data
  },
}
