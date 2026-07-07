import { defineStore } from 'pinia'
import { toastError } from '@/composables/useConfirm'
import { chatApi } from '@/features/collaboration/api'
import { getEcho } from '@/composables/useEcho'
import { useAuthStore } from '@/features/settings/store'
import { cacheSnapshot, serveSnapshot } from '@/features/offline/snapshots'

// Chat state. Messages live in a PER-CONVERSATION map (threads) consumed by the
// /chat page, the tablet two-pane and the dock windows alike — one Echo channel
// per conversation, refcounted, instead of the page and each window owning
// their own copies. Network calls live in api.js.
const PAGE = 50
const TYPING_TTL_MS = 4000
const TYPING_THROTTLE_MS = 2500

function previewOf(message) {
  return (
    {
      image: '📷 Photo',
      voice: '🎤 Voice note',
      file: '📎 File',
    }[message.type] ?? (message.body || 'New message')
  )
}

export const useChatStore = defineStore('chat', {
  state: () => ({
    conversations: [],
    contacts: [],
    // The thread open on the /chat page (it keeps itself marked read). Dock
    // windows are tracked by chatDockStore, not here.
    activeId: null,
    threads: {}, // conversationId -> { messages, loaded, oldestReached, loadingOlder }
    typing: {}, // conversationId -> { [userId]: { name, until } }
    loadingList: false,
    loadingThread: false,
    sending: false,
    error: '',
    _subs: {}, // conversationId -> Echo refcount (page + dock share one channel)
    _lastTypingAt: {}, // conversationId -> ts of my last typing whisper
    _readTimers: {},
    _typingSweeper: null,
  }),

  getters: {
    active: (state) => state.conversations.find((c) => c.id === state.activeId) ?? null,

    // Who is typing in a conversation right now (stale entries swept out).
    typingIn: (state) => (conversationId) => {
      const now = Date.now()
      return Object.values(state.typing[conversationId] ?? {}).filter((t) => t.until > now)
    },
  },

  actions: {
    thread(conversationId) {
      const id = Number(conversationId)
      if (!this.threads[id]) {
        this.threads[id] = { messages: [], loaded: false, oldestReached: false, loadingOlder: false }
      }
      return this.threads[id]
    },

    conversation(conversationId) {
      return this.conversations.find((c) => c.id === Number(conversationId)) ?? null
    },

    async fetchConversations() {
      this.loadingList = true
      try {
        this.conversations = await chatApi.conversations()
        cacheSnapshot('chat:conversations', this.conversations)
      } catch (e) {
        const served = await serveSnapshot(e, 'chat:conversations', (data) => {
          if (!this.conversations.length) this.conversations = data
        })
        if (!served) throw e
      } finally {
        this.loadingList = false
      }
    },

    async fetchContacts() {
      this.contacts = await chatApi.contacts()
    },

    // Oversight threads (project chats read via chat.view_project_chats) aren't
    // in the participant inbox — fetch the one conversation so the header, type
    // and can_post flag still resolve on deep links.
    async ensureConversation(conversationId) {
      if (!this.conversation(conversationId)) {
        this.conversations.unshift(await chatApi.conversation(conversationId))
      }
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
      const id = Number(conversationId)
      this.activeId = id
      this.loadingThread = !this.thread(id).loaded
      try {
        if (!this.conversations.length) await this.fetchConversations()
        await this.ensureConversation(id)
        await this.loadThread(id)
        this.markRead(id)
      } finally {
        this.loadingThread = false
      }
    },

    closeThread() {
      this.activeId = null
    },

    async loadThread(conversationId) {
      const t = this.thread(conversationId)
      let batch
      try {
        batch = await chatApi.messages(conversationId)
        cacheSnapshot(`chat:thread:${Number(conversationId)}`, batch)
      } catch (e) {
        // Offline: a recently-opened thread renders from its snapshot (reading
        // history + queueing replies still works; new live messages resume on
        // reconnect).
        const served = await serveSnapshot(e, `chat:thread:${Number(conversationId)}`, (data) => {
          batch = data
        })
        if (!served) throw e
      }
      // Keep unsent optimistic bubbles at the tail across reloads.
      const keep = t.messages.filter(
        (m) => (m.pending || m.failed) && !batch.some((b) => b.id === m.id),
      )
      t.messages = [...batch, ...keep]
      t.loaded = true
      t.oldestReached = batch.length < PAGE
    },

    // Scroll-up pagination — first consumer of the API's `before` cursor.
    async loadOlder(conversationId) {
      const t = this.thread(conversationId)
      const first = t.messages.find((m) => !m.pending && !m.failed)
      if (t.loadingOlder || t.oldestReached || !first) return 0
      t.loadingOlder = true
      try {
        const older = await chatApi.messages(conversationId, { before: first.id })
        t.messages.unshift(...older.filter((m) => !t.messages.some((x) => x.id === m.id)))
        if (older.length < PAGE) t.oldestReached = true
        return older.length
      } finally {
        t.loadingOlder = false
      }
    },

    // ── Live wiring: one refcounted channel per conversation ──
    retain(conversationId) {
      const id = Number(conversationId)
      const n = (this._subs[id] ?? 0) + 1
      this._subs[id] = n
      if (n > 1) return
      const echo = getEcho()
      if (!echo) return
      echo
        .private(`conversation.${id}`)
        .listen('.message.sent', (p) => this.receive(p))
        .listen('.message.reaction', (p) => this.applyReactions(p))
        .listen('.conversation.read', (p) => this.applyReadCursor(p))
        .listenForWhisper('typing', (p) => this.noteTyping(id, p))
      this._startTypingSweeper()
    },

    release(conversationId) {
      const id = Number(conversationId)
      const n = this._subs[id] ?? 0
      if (n <= 1) {
        delete this._subs[id]
        getEcho()?.leave(`conversation.${id}`)
      } else {
        this._subs[id] = n - 1
      }
    },

    // An incoming broadcast message: append (deduped), bump the inbox preview,
    // clear the author's typing entry. Unread counts + sound stay on the
    // notification path (chatDockStore.noteIncoming). Dock windows mark their
    // own threads read; the store only marks the /chat page's active thread.
    receive(payload) {
      const id = Number(payload.conversation_id)
      const t = this.thread(id)
      const me = useAuthStore().user?.id
      if (t.loaded && !t.messages.some((m) => m.id === payload.id)) {
        t.messages.push({
          reactions: [],
          attachments: [],
          ...payload,
          is_mine: payload.is_mine ?? payload.author?.id === me,
        })
      }
      if (payload.author?.id && this.typing[id]?.[payload.author.id]) {
        delete this.typing[id][payload.author.id]
      }
      const convo = this.conversation(id)
      if (convo) {
        convo.last_message_at = payload.created_at ?? new Date().toISOString()
        convo.last_message = {
          id: payload.id,
          type: payload.type,
          preview: payload.redacted ? 'Message deleted' : previewOf(payload),
          created_at: convo.last_message_at,
        }
      }
      if (this.activeId === id && document.visibilityState !== 'hidden') this.markRead(id)
    },

    // Full reaction set for one message (broadcast) → grouped pills.
    applyReactions({ conversation_id, message_id, reactions }) {
      const m = this.threads[Number(conversation_id)]?.messages.find((x) => x.id === message_id)
      if (!m) return
      m.reactions = this._groupReactions(reactions)
    },

    _groupReactions(flat) {
      const me = useAuthStore().user?.id
      const grouped = {}
      for (const r of flat ?? []) {
        const g = (grouped[r.emoji] ??= { emoji: r.emoji, count: 0, mine: false, users: [] })
        g.count++
        g.users.push({ id: r.user_id, name: r.user_name })
        if (r.user_id === me) g.mine = true
      }
      return Object.values(grouped)
    },

    // A participant's read cursor moved — feeds the ✓✓ seen ticks live.
    applyReadCursor({ conversation_id, user_id, last_read_at }) {
      const convo = this.conversation(conversation_id)
      const p = convo?.participants?.find((x) => x.id === user_id)
      if (p) p.last_read_at = last_read_at
    },

    noteTyping(conversationId, payload) {
      if (!payload?.id || payload.id === useAuthStore().user?.id) return
      this.typing[conversationId] = {
        ...(this.typing[conversationId] ?? {}),
        [payload.id]: { name: payload.name, until: Date.now() + TYPING_TTL_MS },
      }
    },

    // Called on composer input; throttled to one whisper per few seconds.
    sendTyping(conversationId) {
      const id = Number(conversationId)
      const now = Date.now()
      if (now - (this._lastTypingAt[id] ?? 0) < TYPING_THROTTLE_MS) return
      this._lastTypingAt[id] = now
      const me = useAuthStore().user
      try {
        getEcho()?.private(`conversation.${id}`).whisper('typing', { id: me?.id, name: me?.name })
      } catch {
        // Client events unavailable — typing simply doesn't show.
      }
    },

    _startTypingSweeper() {
      if (this._typingSweeper) return
      this._typingSweeper = setInterval(() => {
        const now = Date.now()
        for (const [cid, users] of Object.entries(this.typing)) {
          const alive = Object.fromEntries(
            Object.entries(users).filter(([, t]) => t.until > now),
          )
          if (Object.keys(alive).length !== Object.keys(users).length) {
            this.typing[cid] = alive
          }
        }
      }, 1200)
    },

    // Debounced: clears the badge at once, posts the cursor shortly after (one
    // POST per burst of incoming messages, one ConversationRead broadcast).
    markRead(conversationId) {
      const id = Number(conversationId)
      const convo = this.conversation(id)
      if (convo) convo.unread_count = 0
      clearTimeout(this._readTimers[id])
      this._readTimers[id] = setTimeout(() => {
        chatApi.markRead(id).catch(() => {})
      }, 400)
    },

    // ── Sending, WhatsApp-style: optimistic bubble (clock) → ack (tick) ──
    sendText(conversationId, body, replyTo = null) {
      const value = (body ?? '').trim()
      if (!value) return
      const message = this._optimistic(conversationId, {
        type: 'text',
        body: value,
        payload: { body: value, replyToId: replyTo?.id ?? null },
        replyTo,
      })
      return this._send(conversationId, message)
    },

    sendAttachment(conversationId, file, durationMs = null, replyTo = null) {
      if (!file) return
      const kind = file.type?.startsWith('image/')
        ? 'image'
        : file.type?.startsWith('audio/') || file.type?.startsWith('video/')
          ? 'voice'
          : 'file'
      const message = this._optimistic(conversationId, {
        type: kind,
        body: null,
        attachments: [
          {
            id: `local-${Date.now()}`,
            kind,
            mime_type: file.type,
            duration_ms: durationMs != null ? Math.round(durationMs) : null,
            url: URL.createObjectURL(file),
            local: true,
          },
        ],
        payload: { file, durationMs, replyToId: replyTo?.id ?? null },
        replyTo,
      })
      return this._send(conversationId, message)
    },

    _optimistic(conversationId, { type, body, attachments = [], payload, replyTo }) {
      const me = useAuthStore().user
      const clientKey =
        globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`
      const message = {
        id: `tmp:${clientKey}`,
        client_key: clientKey,
        pending: true,
        failed: false,
        conversation_id: Number(conversationId),
        type,
        body,
        redacted: false,
        is_mine: true,
        author: { id: me?.id, name: me?.name, avatar_url: me?.avatar_url ?? null },
        attachments,
        reactions: [],
        reply_to: replyTo
          ? {
              id: replyTo.id,
              author_id: replyTo.author?.id ?? null,
              author_name: replyTo.author?.name ?? null,
              type: replyTo.type,
              redacted: !!replyTo.redacted,
              excerpt: replyTo.redacted ? null : previewOf(replyTo).slice(0, 80),
            }
          : null,
        created_at: new Date().toISOString(),
        _payload: payload,
      }
      this.thread(conversationId).messages.push(message)
      return message
    },

    async _send(conversationId, message) {
      this.sending = true
      this.error = ''
      message.pending = true
      message.failed = false
      try {
        const p = message._payload
        const form = new FormData()
        if (p.body) form.append('body', p.body)
        if (p.file) form.append('attachment', p.file)
        if (p.durationMs != null) form.append('duration_ms', String(Math.round(p.durationMs)))
        if (p.replyToId) form.append('reply_to_id', String(p.replyToId))
        const saved = await chatApi.sendMessage(conversationId, form)
        this._resolvePending(conversationId, message.client_key, saved)
        return saved
      } catch (e) {
        // The bubble turns into "Not sent — retry / discard"; a server rejection
        // (thread went read-only, project closed) also gets its reason toasted.
        message.pending = false
        message.failed = true
        this.error = e.response?.data?.message ?? 'Could not send the message.'
        if (e.response) toastError(this.error)
        return null
      } finally {
        this.sending = false
      }
    },

    _resolvePending(conversationId, clientKey, saved) {
      const t = this.thread(conversationId)
      const idx = t.messages.findIndex((m) => m.client_key === clientKey)
      if (saved && t.messages.some((m) => m.id === saved.id)) {
        // The broadcast echo landed first — drop the optimistic copy.
        if (idx !== -1) t.messages.splice(idx, 1)
      } else if (idx !== -1) {
        t.messages.splice(idx, 1, { reactions: [], ...saved })
      } else if (saved) {
        this.receive(saved)
      }
      const convo = this.conversation(conversationId)
      if (convo && saved) {
        convo.last_message_at = saved.created_at
        convo.last_message = {
          id: saved.id,
          type: saved.type,
          preview: previewOf(saved),
          created_at: saved.created_at,
        }
      }
    },

    retrySend(conversationId, message) {
      if (!message?._payload) return
      return this._send(conversationId, message)
    },

    discardPending(conversationId, clientKey) {
      const t = this.thread(conversationId)
      t.messages = t.messages.filter((m) => m.client_key !== clientKey)
    },

    // Optimistic toggle, reconciled by the HTTP response (and the broadcast).
    async react(message, emoji) {
      const before = message.reactions
      const mine = before.find((g) => g.mine)
      message.reactions = this._toggleLocal(before, emoji, mine)
      try {
        const saved = await chatApi.react(message.id, emoji)
        message.reactions = saved.reactions ?? message.reactions
      } catch (e) {
        message.reactions = before
        toastError(e.response?.data?.message ?? 'Could not react to the message.')
      }
    },

    _toggleLocal(groups, emoji, mine) {
      const me = useAuthStore().user
      let next = groups
        .map((g) => {
          if (!g.mine) return g
          // Remove my old reaction (toggle-off or replace).
          return { ...g, mine: false, count: g.count - 1, users: g.users.filter((u) => u.id !== me?.id) }
        })
        .filter((g) => g.count > 0)
      if (!mine || mine.emoji !== emoji) {
        const existing = next.find((g) => g.emoji === emoji)
        next = existing
          ? next.map((g) =>
              g === existing
                ? { ...g, mine: true, count: g.count + 1, users: [...g.users, { id: me?.id, name: me?.name }] }
                : g,
            )
          : [...next, { emoji, count: 1, mine: true, users: [{ id: me?.id, name: me?.name }] }]
      }
      return next
    },

    async deleteMessage(conversationId, messageId) {
      const { data } = await chatApi.deleteMessage(messageId)
      const t = this.thread(conversationId)
      const idx = t.messages.findIndex((m) => m.id === messageId)
      if (idx !== -1) t.messages.splice(idx, 1, data.data)
    },

    async toggleMute(conversationId) {
      const { muted } = await chatApi.toggleMute(conversationId)
      const convo = this.conversation(conversationId)
      if (convo) convo.is_muted = muted
      return muted
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
      this.receive({ ...message, conversation_id: Number(conversationId) })
      return message
    },
  },
})
