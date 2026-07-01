import { useApi } from '@/composables/useApi'

// Network calls for the Payments feature (schedules, versements, documents).
// Kept as the single place the feature talks to the API, like the other features.

// The instalment plan for a deal (client_project).
export const scheduleApi = {
  async get(projectId) {
    const { data } = await useApi().get(`/projects/${projectId}/schedule`)
    return data.data
  },

  // installments = [{ due_date, amount }] — server reconciles to total_price.
  async save(projectId, installments) {
    const { data } = await useApi().put(`/projects/${projectId}/schedule`, { installments })
    return data.data
  },
}

// Recorded instalment payments on a deal. The list response also carries a
// server-computed running balance under `meta`.
export const versementsApi = {
  async list(projectId) {
    const { data } = await useApi().get(`/projects/${projectId}/versements`)
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
