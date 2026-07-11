import { useApi } from '@/composables/useApi'

// Staff inbox for public-site leads (permission web.leads).
export const webLeadsApi = {
  async list(params = {}) {
    const { data } = await useApi().get('/web-leads', { params })
    return data
  },
  async markHandled(id) {
    const { data } = await useApi().post(`/web-leads/${id}/handle`)
    return data.data
  },
  async markSpam(id) {
    const { data } = await useApi().post(`/web-leads/${id}/spam`)
    return data.data
  },
  // 409 (duplicate phone) is a normal outcome here — the modal handles it.
  convert: (id, payload) => useApi().post(`/web-leads/${id}/convert`, payload),
}
