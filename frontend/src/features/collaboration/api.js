import { useApi } from '@/composables/useApi'

// Network calls for the Collaboration feature (notifications now; chat added in a
// later slice). State lives in the stores; these are the only place the feature
// talks to the API, via the shared useApi (cookie auth).
export const notificationsApi = {
  async list(params = {}) {
    const { data } = await useApi().get('/notifications', { params })
    return data // { data: [...], meta: { unread_count }, links }
  },

  async markRead(id) {
    const { data } = await useApi().post(`/notifications/${id}/read`)
    return data // { unread_count }
  },

  async markAllRead() {
    const { data } = await useApi().post('/notifications/read-all')
    return data // { unread_count }
  },
}
