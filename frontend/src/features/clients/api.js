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

// Staff directory (id + name of active users) — feeds the "share this project
// with a colleague" picker.
export const staffApi = {
  async list() {
    const { data } = await useApi().get('/staff')
    return data.data
  },
}

// Who can see a project: the creator + the users it was shared with. Viewers
// are hidden, never removed; re-adding un-hides.
export const projectViewersApi = {
  async list(projectId) {
    const { data } = await useApi().get(`/projects/${projectId}/viewers`)
    return data.data
  },

  async add(projectId, userId) {
    const { data } = await useApi().post(`/projects/${projectId}/viewers`, { user_id: userId })
    return data.data
  },

  async hide(projectId, userId) {
    const { data } = await useApi().post(`/projects/${projectId}/viewers/${userId}/hide`)
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
export const reserveUnit = (unitId, payload = {}) =>
  useApi().post(`/units/${unitId}/reserve`, payload)

// The deal's property shortlist (units/boxes the client wants), set at the office
// visit. `sync` replaces the active set with the given list (add / keep / remove).
export const shortlistApi = {
  async list(projectId) {
    const { data } = await useApi().get(`/projects/${projectId}/shortlist`)
    return data.data
  },

  async sync(projectId, items, officeVisitId = null) {
    const { data } = await useApi().put(`/projects/${projectId}/shortlist`, {
      items,
      office_visit_id: officeVisitId,
    })
    return data.data
  },

  // Phase-6 closure: win (with total_price) or lose an interested property.
  async outcome(itemId, payload) {
    const { data } = await useApi().post(`/shortlist-items/${itemId}/outcome`, payload)
    return data.data
  },
}

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

  archive(projectId, payload) {
    return useApi().post(`/projects/${projectId}/archive`, payload)
  },

  reactivate(projectId) {
    return useApi().post(`/projects/${projectId}/reactivate`)
  },

  // Client changed their mind: archive the deal + put them back on the desire list.
  shiftToDesire(projectId, payload) {
    return useApi().post(`/projects/${projectId}/shift-to-desire`, payload)
  },

  cancel(projectId, reason) {
    return useApi().delete(`/projects/${projectId}`, { data: { reason } })
  },
}

// The dedicated "Desire matches" board — waiting clients whose criteria now fit
// available inventory (agent-scoped on the server).
export const desireMatchesApi = {
  async list() {
    const { data } = await useApi().get('/desires/matches')
    return data.data
  },
}

// Deals on a project: opened from a visit log with the properties the client is
// interested in (auto-reserved), then closed won (agreed price) or lost. Boxes
// reserved alongside the apartment can be re-set while the deal is open.
export const dealsApi = {
  async list(projectId) {
    const { data } = await useApi().get(`/projects/${projectId}/deals`)
    return data.data
  },

  async create(projectId, payload) {
    const { data } = await useApi().post(`/projects/${projectId}/deals`, payload)
    return data.data
  },

  async close(dealId, payload) {
    const { data } = await useApi().post(`/deals/${dealId}/close`, payload)
    return data.data
  },

  async syncBoxes(dealId, boxIds) {
    const { data } = await useApi().put(`/deals/${dealId}/boxes`, { box_ids: boxIds })
    return data.data
  },
}
