import { useApi } from '@/composables/useApi'

// Network calls for the Payments feature (schedules, versements, documents).
// Kept as the single place the feature talks to the API, like the other features.

// The cross-project follow-up hub (/payments): holdings on hold, reservations,
// and the instalments to chase.
export const paymentsOverviewApi = {
  async get() {
    const { data } = await useApi().get('/payments/overview')
    return data.data
  },
}

// The instalment plans on a deal — one per won apartment (unitId scopes them;
// null only for legacy project-level plans).
export const scheduleApi = {
  async get(projectId, unitId = null) {
    const { data } = await useApi().get(`/projects/${projectId}/schedule`, {
      params: unitId ? { unit_id: unitId } : {},
    })
    return data.data
  },

  // installments = [{ due_date, amount }] — the server reconciles them to the
  // apartment's agreed price (the project total when unitId is null).
  async save(projectId, installments, unitId = null) {
    const { data } = await useApi().put(`/projects/${projectId}/schedule`, {
      installments,
      ...(unitId ? { unit_id: unitId } : {}),
    })
    return data.data
  },
}

// Recorded instalment payments on a deal, per apartment. The list response also
// carries a server-computed running balance under `meta` (scoped to the unit).
export const versementsApi = {
  async list(projectId, unitId = null) {
    const { data } = await useApi().get(`/projects/${projectId}/versements`, {
      params: unitId ? { unit_id: unitId } : {},
    })
    return { items: data.data, meta: data.meta ?? {} }
  },

  async record(projectId, payload) {
    const { data } = await useApi().post(`/projects/${projectId}/versements`, payload)
    return data.data
  },

  // Cancel-and-duplicate correction: returns the new (corrected) versement.
  async correct(versementId, payload) {
    const { data } = await useApi().post(`/versements/${versementId}/correct`, payload)
    return data.data
  },

  // Refund a done payment (the money went back): { reason }. The row stays in
  // history flagged refunded and drops out of the balance.
  async refund(versementId, payload) {
    const { data } = await useApi().post(`/versements/${versementId}/refund`, payload)
    return data.data
  },
}

// Branded documents (receipts). Generation renders on the queue worker; the file
// downloads through a permission-gated endpoint (relative URL for cookie auth).
export const documentsApi = {
  async generateReceipt(versementId) {
    const { data } = await useApi().post(`/versements/${versementId}/document`)
    return data.data
  },

  async get(documentId) {
    const { data } = await useApi().get(`/documents/${documentId}`)
    return data.data
  },
}
