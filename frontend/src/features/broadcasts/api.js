import { useApi } from '@/composables/useApi'

// Network calls for custom broadcast notifications: compose + send a message to
// selected users / a role / everyone, the company-wide sent history, and one
// broadcast's full detail. State lives in the store; this is the only place the
// feature talks to the API (shared useApi, cookie auth).
export const broadcastsApi = {
  // Send a broadcast. A per-send idempotency key makes a double-tap (or a
  // retried request whose response was lost) send exactly once, not twice.
  async send(payload) {
    const { data } = await useApi().post('/broadcasts', payload, {
      headers: { 'X-Idempotency-Key': crypto.randomUUID() },
    })
    return data.data
  },

  async list(params = {}) {
    const { data } = await useApi().get('/broadcasts', { params })
    return data // { data: [...], links, meta: { current_page, last_page, ... } }
  },

  async show(id) {
    const { data } = await useApi().get(`/broadcasts/${id}`)
    return data.data
  },
}
