import { useApi } from '@/composables/useApi'

// Network calls for the Pipeline feature: the interaction chain (calls, visits)
// and the enforced next action. The timeline is rendered on the client file.
export const pipelineApi = {
  async timeline(clientId) {
    const { data } = await useApi().get(`/clients/${clientId}/timeline`)
    return data.data // { calls, visits, next_actions }
  },

  async logCall(clientId, payload) {
    const { data } = await useApi().post(`/clients/${clientId}/calls`, payload)
    return data.data
  },

  async scheduleVisit(payload) {
    const { data } = await useApi().post('/visits', payload)
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

  // Available units — feeds the apartment-visit unit picker (loaded on demand).
  async availableUnits() {
    const { data } = await useApi().get('/units', { params: { sale_status: 'available' } })
    return data.data
  },
}
