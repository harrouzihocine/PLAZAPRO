import { useApi } from '@/composables/useApi'

// Network calls for the Collaboration feature (notifications now; chat added in a
// later slice). State lives in the stores; these are the only place the feature
// talks to the API, via the shared useApi (cookie auth).
export const notificationsApi = {
  async list(params = {}) {
    const { data } = await useApi().get('/notifications', { params })
    return data // { data: [...], links, meta: { current_page, last_page, ... }, unread_count }
  },

  async markRead(id) {
    const { data } = await useApi().post(`/notifications/${id}/read`)
    return data // { unread_count }
  },

  async markUnread(id) {
    const { data } = await useApi().post(`/notifications/${id}/unread`)
    return data // { unread_count }
  },

  async markAllRead() {
    const { data } = await useApi().post('/notifications/read-all')
    return data // { unread_count }
  },
}

// Chat: conversations, messages and attachments. All gated chat.use; per-thread
// access is participant-scoped server-side.
export const chatApi = {
  async contacts() {
    const { data } = await useApi().get('/chat/contacts')
    return data.data
  },

  async conversations() {
    const { data } = await useApi().get('/conversations')
    return data.data
  },

  // One conversation (deep links / oversight threads not in the inbox).
  async conversation(id) {
    const { data } = await useApi().get(`/conversations/${id}`)
    return data.data
  },

  async createConversation(payload) {
    const { data } = await useApi().post('/conversations', payload)
    return data.data
  },

  // A project's dedicated chat (find-or-create, contributor-scoped).
  async projectConversation(projectId) {
    const { data } = await useApi().get(`/projects/${projectId}/conversation`)
    return data.data
  },

  async messages(conversationId, params = {}) {
    const { data } = await useApi().get(`/conversations/${conversationId}/messages`, { params })
    return data.data
  },

  // `payload` is a FormData (body and/or attachment + duration_ms) so files work.
  async sendMessage(conversationId, payload) {
    const { data } = await useApi().post(`/conversations/${conversationId}/messages`, payload)
    return data.data
  },

  markRead(conversationId) {
    return useApi().post(`/conversations/${conversationId}/read`)
  },

  deleteMessage(messageId) {
    return useApi().delete(`/messages/${messageId}`)
  },

  async addParticipants(conversationId, userIds) {
    const { data } = await useApi().post(`/conversations/${conversationId}/participants`, {
      user_ids: userIds,
    })
    return data.data
  },

  removeParticipant(conversationId, userId) {
    return useApi().delete(`/conversations/${conversationId}/participants/${userId}`)
  },

  async shareRecord(conversationId, subjectType, subjectId, note = null) {
    const { data } = await useApi().post(`/conversations/${conversationId}/share`, {
      subject_type: subjectType,
      subject_id: subjectId,
      note,
    })
    return data.data
  },
}
