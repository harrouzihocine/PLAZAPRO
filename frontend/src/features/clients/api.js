import { useApi } from '@/composables/useApi'

// Network calls for the Clients feature. State lives in clientsStore.js; these
// functions are the only place the feature talks to the API (via shared useApi).
export const clientsApi = {
  async list(params = {}) {
    const { data } = await useApi().get('/clients', { params })
    return data.data
  },

  async get(id) {
    const { data } = await useApi().get(`/clients/${id}`)
    return data.data
  },

  async create(payload) {
    const { data } = await useApi().post('/clients', payload)
    return data.data
  },

  async update(id, payload) {
    const { data } = await useApi().put(`/clients/${id}`, payload)
    return data.data
  },

  cancel(id, reason) {
    return useApi().delete(`/clients/${id}`, { data: { reason } })
  },
}

// Active agents (role.is_agent) — feeds the visit/next-action assign pickers
// (field agents). Open to any authenticated user, like the other pickers.
export const agentsApi = {
  async list() {
    const { data } = await useApi().get('/agents')
    return data.data
  },
}

// Follow-up agents (role can log calls) — the sales agents/managers who follow a
// client up. Feeds the client "assigned agent" picker, distinct from the visit
// (field-agent) picker above.
export const followUpAgentsApi = {
  async list() {
    const { data } = await useApi().get('/follow-up-agents')
    return data.data
  },
}

// A client's desire (matching criteria) and the inventory it matches.
export const desireApi = {
  async get(clientId) {
    const { data } = await useApi().get(`/clients/${clientId}/desire`)
    return data.data // may be null if none captured yet
  },

  async save(clientId, payload) {
    const { data } = await useApi().put(`/clients/${clientId}/desire`, payload)
    return data.data
  },

  async matches(clientId) {
    const { data } = await useApi().get(`/clients/${clientId}/matches`)
    return data.data
  },
}

// Reserve a unit (48h hold) — reused from the matches panel's one-tap "reserve".
export const reserveUnit = (unitId, payload = {}) => useApi().post(`/units/${unitId}/reserve`, payload)

// Deals (client_projects) hanging off a client. Stage moves through /advance.
export const projectsApi = {
  async list(clientId, status) {
    const params = status ? { status } : {}
    const { data } = await useApi().get(`/clients/${clientId}/projects`, { params })
    return data.data
  },

  async create(clientId, payload) {
    const { data } = await useApi().post(`/clients/${clientId}/projects`, payload)
    return data.data
  },

  async update(projectId, payload) {
    const { data } = await useApi().put(`/projects/${projectId}`, payload)
    return data.data
  },

  async advance(projectId, stage) {
    const { data } = await useApi().post(`/projects/${projectId}/advance`, { stage })
    return data.data
  },

  archive(projectId) {
    return useApi().post(`/projects/${projectId}/archive`)
  },

  reactivate(projectId) {
    return useApi().post(`/projects/${projectId}/reactivate`)
  },

  cancel(projectId, reason) {
    return useApi().delete(`/projects/${projectId}`, { data: { reason } })
  },
}
