import { defineStore } from 'pinia'
import { toastError } from '@/composables/useConfirm'
import { chatApi } from '@/features/collaboration/api'
import { getEcho } from '@/composables/useEcho'

// Chat state: the inbox (conversations), the contact picker, and the currently
// open thread with live updates over the conversation's private channel. Network
// calls live in api.js. Inbox badges refresh on fetch; the open thread streams.
export const useChatStore = defineStore('chat', {
  state: () => ({
    conversations: [],
    contacts: [],
    activeId: null,
    messages: [],
    loadingList: false,
    loadingThread: false,
    sending: false,
    error: '',
    _channelId: null, // conversation id currently subscribed on Echo
  }),

  getters: {
    active: (state) => state.conversations.find((c) => c.id === state.activeId) ?? null,
  },

  actions: {
    async fetchConversations() {
      this.loadingList = true
      try {
        this.conversations = await chatApi.conversations()
      } finally {
        this.loadingList = false
      }
    },

    async fetchContacts() {
      this.contacts = await chatApi.contacts()
    },

    async startDirect(userId) {
      const convo = await chatApi.createConversation({ type: 'direct', participant_ids: [userId] })
      await this.fetchConversations()
      return convo.id
    },

    async createGroup(title, participantIds) {
      const convo = await chatApi.createConversation({
        type: 'group',
        title,
        participant_ids: participantIds,
      })
      await this.fetchConversations()
      return convo.id
    },

    async openThread(conversationId) {
      this.activeId = conversationId
      this.loadingThread = true
      try {
        // Ensure the inbox is loaded so the header/title resolves when the thread
        // is opened directly (e.g. deep-linked from a notification).
        if (!this.conversations.length) await this.fetchConversations()
        this.messages = await chatApi.messages(conversationId)
        await this.markRead(conversationId)
        this.subscribe(conversationId)
      } finally {
        this.loadingThread = false
      }
    },

    async markRead(conversationId) {
      await chatApi.markRead(conversationId)
      const convo = this.conversations.find((c) => c.id === conversationId)
      if (convo) convo.unread_count = 0
    },

    appendMessage(message) {
      if (!message || this.messages.some((m) => m.id === message.id)) return
      this.messages.push(message)
    },

    async sendText(body) {
      if (!this.activeId || !body.trim()) return
      const form = new FormData()
      form.append('body', body.trim())
      await this._send(form)
    },

    async sendAttachment(file, durationMs = null) {
      if (!this.activeId || !file) return
      const form = new FormData()
      form.append('attachment', file)
      if (durationMs != null) form.append('duration_ms', String(Math.round(durationMs)))
      await this._send(form)
    },

    async _send(form) {
      this.sending = true
      this.error = ''
      try {
        const message = await chatApi.sendMessage(this.activeId, form)
        this.appendMessage(message)
      } catch (e) {
        this.error = e.response?.data?.message ?? 'Could not send the message.'
        toastError(this.error)
        throw e
      } finally {
        this.sending = false
      }
    },

    async deleteMessage(messageId) {
      const { data } = await chatApi.deleteMessage(messageId)
      const idx = this.messages.findIndex((m) => m.id === messageId)
      if (idx !== -1) this.messages[idx] = data.data
    },

    async addParticipants(conversationId, userIds) {
      const updated = await chatApi.addParticipants(conversationId, userIds)
      const idx = this.conversations.findIndex((c) => c.id === conversationId)
      if (idx !== -1) this.conversations[idx] = updated
      return updated
    },

    async removeParticipant(conversationId, userId) {
      await chatApi.removeParticipant(conversationId, userId)
      await this.fetchConversations()
    },

    async shareRecord(conversationId, subjectType, subjectId, note = null) {
      const message = await chatApi.shareRecord(conversationId, subjectType, subjectId, note)
      if (this.activeId === conversationId) this.appendMessage(message)
      return message
    },

    // Live: subscribe to the open thread's private channel; append incoming
    // messages (deduped) and keep the thread marked read while it's open.
    subscribe(conversationId) {
      const echo = getEcho()
      if (!echo) return
      if (this._channelId && this._channelId !== conversationId) this.unsubscribe()
      if (this._channelId === conversationId) return

      echo.private(`conversation.${conversationId}`).listen('.message.sent', (payload) => {
        this.appendMessage(payload)
        if (this.activeId === conversationId) this.markRead(conversationId)
      })
      this._channelId = conversationId
    },

    unsubscribe() {
      if (!this._channelId) return
      const echo = getEcho()
      echo?.leave(`conversation.${this._channelId}`)
      this._channelId = null
    },
  },
})
