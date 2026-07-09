import { useApi } from '@/composables/useApi'

// Network calls for the Analytics feature (dashboard, reports, audit). Analytics
// is a read side: every call here is a GET. The single place the feature talks
// to the API, like the other features.

export const analyticsApi = {
  // Role-aware KPIs + short lists, scoped server-side to the caller.
  async dashboard() {
    const { data } = await useApi().get('/dashboard')
    return data.data
  },

  // Source ROI cohort report. params: { from, to } (both optional, YYYY-MM-DD).
  async sourceRoi(params = {}) {
    const { data } = await useApi().get('/analytics/source-roi', { params })
    return data.data
  },

  // Per-unit interest / holds / conversion. params: { location_id } (optional).
  async units(params = {}) {
    const { data } = await useApi().get('/analytics/units', { params })
    return data.data
  },

  // Team Logs feed — self-scoped to the caller unless they hold logs.view_all,
  // which widens it company-wide (user_id then filters to one person). Returns
  // the paginated feed rows, a per-type summary and pagination meta.
  // params: { user_id, type, from, to, mode (logged|upcoming), page }.
  async teamLogs(params = {}) {
    const { data } = await useApi().get('/team-logs', { params })
    return { items: data.data, summary: data.summary ?? {}, meta: data.meta ?? {} }
  },

  // Voice-of-Client feedback for a development. params: { from, to } (optional).
  async locationFeedback(id, params = {}) {
    const { data } = await useApi().get(`/analytics/locations/${id}/feedback`, { params })
    return data.data
  },

  // Voice-of-Client feedback drilled into a single unit. params: { from, to }.
  async unitFeedback(id, params = {}) {
    const { data } = await useApi().get(`/analytics/units/${id}/feedback`, { params })
    return data.data
  },

  // ── KPI command center ────────────────────────────────────────────────────
  // Every section takes the shared global filters as query params:
  // { period, from, to, location_id, unit_type, agent_id }. One GET per tab so
  // the board lazy-loads only what's on screen.
  async kpiSection(name, params = {}) {
    const { data } = await useApi().get(`/analytics/kpi/${name}`, { params })
    return data.data
  },

  // Reference data for the filter bar's dimension selects.
  async kpiFilters() {
    const { data } = await useApi().get('/analytics/kpi/filters')
    return data.data
  },

  // Trend series from the nightly snapshots. params: { metrics, days, location_id }.
  async kpiTrends(params = {}) {
    const { data } = await useApi().get('/analytics/kpi/trends', { params })
    return data.data
  },

  async kpiTargets() {
    const { data } = await useApi().get('/analytics/kpi/targets')
    return data.data
  },
  async saveKpiTarget(body) {
    const { data } = await useApi().put('/analytics/kpi/targets', body)
    return data.data
  },
  async kpiCosts() {
    const { data } = await useApi().get('/analytics/kpi/costs')
    return data.data
  },
  async saveKpiCost(body) {
    const { data } = await useApi().put('/analytics/kpi/costs', body)
    return data.data
  },
}

export const auditApi = {
  // Paginated, filterable audit feed. params: { user_id, action, subject_type, from, to, page }.
  async list(params = {}) {
    const { data } = await useApi().get('/audit', { params })
    return { items: data.data, meta: data.meta ?? {} }
  },

  // Streams the filtered feed as CSV. Returns the Blob; the server also records
  // an `export` entry in the audit trail. The view turns the Blob into a download.
  async export(params = {}) {
    const { data } = await useApi().get('/audit/export', { params, responseType: 'blob' })
    return data
  },
}
