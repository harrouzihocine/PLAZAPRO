import { useApi } from '@/composables/useApi'

// The Team-Oversight center: read-only anomaly monitors (+ clearing a stuck
// draft, + the sidebar badge summary). Each endpoint is gated server-side by
// its own oversight.* permission; all accept from/to/user_id filter params.
export const oversightApi = {
  async clients(params = {}) {
    const { data } = await useApi().get('/oversight/clients', { params })
    return data.data
  },
  async pipeline(params = {}) {
    const { data } = await useApi().get('/oversight/pipeline', { params })
    return data.data
  },
  async lostPaidDeals(params = {}) {
    const { data } = await useApi().get('/oversight/lost-paid-deals', { params })
    return data.data
  },
  async drafts(params = {}) {
    const { data } = await useApi().get('/oversight/drafts', { params })
    return data.data
  },
  // The Archive desk: paginated archived (lost/closed) projects + a summary strip.
  // Returns { items, meta, summary }. params: { search, reason, location_id,
  // agent_id, from, to, min_price, max_price, sort, page }.
  async archive(params = {}) {
    const { data } = await useApi().get('/oversight/archive', { params })
    return data.data
  },
  // Streams the filtered archive as CSV (Blob). The server records the export in
  // the audit trail; the view turns the Blob into a download.
  async exportArchive(params = {}) {
    const { data } = await useApi().get('/oversight/archive/export', {
      params,
      responseType: 'blob',
    })
    return data
  },
  async summary() {
    const { data } = await useApi().get('/oversight/summary')
    return data.data
  },
  removeDraft(id) {
    return useApi().delete(`/oversight/drafts/${id}`)
  },
}
