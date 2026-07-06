import { useApi } from '@/composables/useApi'

// Server-side mirror of the user's own draft METADATA (never the form contents),
// so the drafts-oversight page can see and clear stuck work. Best-effort — the
// authoritative copy (with data) lives in localStorage.
export const draftsApi = {
  async list() {
    const { data } = await useApi().get('/me/drafts')
    return data.data
  },
  upsert(payload) {
    return useApi().post('/me/drafts', payload)
  },
  remove(key) {
    return useApi().delete(`/me/drafts/${encodeURIComponent(key)}`)
  },
}
