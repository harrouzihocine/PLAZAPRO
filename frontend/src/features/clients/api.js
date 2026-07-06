import { useApi } from '@/composables/useApi'

// The supervised duplicate-client queue (clients.duplicates.resolve).
export const duplicateRequestsApi = {
  async list() {
    const { data } = await useApi().get('/clients/duplicate-requests')
    return data.data
  },
  async resolve(id, payload) {
    const { data } = await useApi().post(`/clients/duplicate-requests/${id}/resolve`, payload)
    return data.data
  },
  // Inspect one of the existing client's projects (activity, stage, the brief)
  // before deciding — deny / share / start a separate project.
  async previewProject(id, projectId) {
    const { data } = await useApi().get(
      `/clients/duplicate-requests/${id}/projects/${projectId}`,
    )
    return data.data
  },
}

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

// Project handlers (role can open a client project — projects.create). Feeds the
// archive reactivation hand-off picker (who a project may be given to).
export const projectHandlersApi = {
  async list() {
    const { data } = await useApi().get('/project-handlers')
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

  // Deliberate close-down to new activity (payments still flow) — and back.
  freeze(projectId) {
    return useApi().post(`/projects/${projectId}/freeze`)
  },

  unfreeze(projectId) {
    return useApi().post(`/projects/${projectId}/unfreeze`)
  },

  // Reactivate an archived project. With no payload it is a plain reactivate;
  // with { handler_ids, mode, primary_id } it hands the project to a chosen team
  // (as-is or a separate new project — see ReactivateProjectWithHandoff).
  reactivate(projectId, payload = {}) {
    return useApi().post(`/projects/${projectId}/reactivate`, payload)
  },

  // Who to show before reactivating: opener + current contributors + in-site agents.
  async handoffPreview(projectId) {
    const { data } = await useApi().get(`/projects/${projectId}/handoff-preview`)
    return data.data
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

  // Delegate a waiting client to the sales agent who will reconnect (manager
  // action). Sets the client's assigned agent + notifies them; the lead then
  // shows on that agent's board. Returns the refreshed client.
  async assign(clientId, agentId) {
    const { data } = await useApi().post(`/clients/${clientId}/assign-agent`, { agent_id: agentId })
    return data.data
  },
}

// Deals on a project: opened from an interaction log (visit or call) with the
// properties the client is interested in (auto-reserved). Each APARTMENT closes
// won (its own agreed price) or lost on its own; the whole-deal close is the
// bulk face of the same flow. Boxes are edited per apartment while it's open.
export const dealsApi = {
  async list(projectId) {
    const { data } = await useApi().get(`/projects/${projectId}/deals`)
    return data.data
  },

  // Who worked the project (pool + defaults for the "who deserves credit" pickers
  // on a sale): { participants: [{id,name}], insite_agent_ids, sale_agent_ids }.
  async participants(projectId) {
    const { data } = await useApi().get(`/projects/${projectId}/participants`)
    return data.data
  },

  async create(projectId, payload) {
    const { data } = await useApi().post(`/projects/${projectId}/deals`, payload)
    return data.data
  },

  // Bulk: { outcome: 'won', items: [{item_id, agreed_price}] } or
  //       { outcome: 'lost', resolution, note }.
  async close(dealId, payload) {
    const { data } = await useApi().post(`/deals/${dealId}/close`, payload)
    return data.data
  },

  // One apartment: { outcome, agreed_price?, resolution?, note? }.
  async closeItem(dealId, itemId, payload) {
    const { data } = await useApi().post(`/deals/${dealId}/items/${itemId}/close`, payload)
    return data.data
  },

  // Release a WON apartment (the sale fell through): { resolution?, note? }.
  async releaseItem(dealId, itemId, payload) {
    const { data } = await useApi().post(`/deals/${dealId}/items/${itemId}/release`, payload)
    return data.data
  },

  // Sell extra boxes onto a WON apartment: { box_ids, added_price? }.
  async addBoxes(dealId, itemId, payload) {
    const { data } = await useApi().post(`/deals/${dealId}/items/${itemId}/boxes/add`, payload)
    return data.data
  },

  async syncUnitBoxes(dealId, itemId, boxIds) {
    const { data } = await useApi().put(`/deals/${dealId}/items/${itemId}/boxes`, {
      box_ids: boxIds,
    })
    return data.data
  },
}
