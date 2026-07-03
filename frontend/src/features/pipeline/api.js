import { useApi } from '@/composables/useApi'

// Network calls for the Pipeline feature: the interaction chain (calls, visits)
// and the enforced next action. The timeline is rendered on the client file.
export const pipelineApi = {
  // Optionally scoped to one project's story (its logs + the client-level ones).
  async timeline(clientId, projectId = null) {
    const params = projectId ? { project_id: projectId } : {}
    const { data } = await useApi().get(`/clients/${clientId}/timeline`, { params })
    return data.data // { calls, visits, next_actions }
  },

  async logCall(clientId, payload) {
    const { data } = await useApi().post(`/clients/${clientId}/calls`, payload)
    return data.data
  },

  // Plan a next action after the fact (for a log that didn't need one then).
  async createNextAction(clientId, payload) {
    const { data } = await useApi().post(`/clients/${clientId}/next-actions`, payload)
    return data.data
  },

  async assignVisit(visitId, agentId) {
    const { data } = await useApi().post(`/visits/${visitId}/assign`, { agent_id: agentId })
    return data.data
  },

  async completeVisit(visitId, payload) {
    const { data } = await useApi().post(`/visits/${visitId}/complete`, payload)
    return data.data
  },

  // Corrections — every edit is a cancel + new version, so a reason is required.
  async correctCall(callId, payload) {
    const { data } = await useApi().post(`/calls/${callId}/correct`, payload)
    return data.data
  },

  async correctVisit(visitId, payload) {
    const { data } = await useApi().post(`/visits/${visitId}/correct`, payload)
    return data.data
  },

  async correctNextAction(nextActionId, payload) {
    const { data } = await useApi().post(`/next-actions/${nextActionId}/correct`, payload)
    return data.data
  },

  // The dispatch board (visits.dispatch): pending in-site pool + agent week grid.
  async dispatchBoard(week = null) {
    const { data } = await useApi().get('/dispatch/board', { params: week ? { week } : {} })
    return data.data
  },

  async dispatchAssign(changes) {
    const { data } = await useApi().post('/dispatch/assign', { changes })
    return data
  },
}

// Tasks (to-dos) backing the Phase 5 tasks board. All gated tasks.manage.
export const tasksApi = {
  async list(params = {}) {
    const { data } = await useApi().get('/tasks', { params })
    return data.data
  },

  async create(payload) {
    const { data } = await useApi().post('/tasks', payload)
    return data.data
  },

  async complete(id) {
    const { data } = await useApi().post(`/tasks/${id}/complete`)
    return data.data
  },

  cancel(id, reason) {
    return useApi().delete(`/tasks/${id}`, { data: { reason } })
  },
}
