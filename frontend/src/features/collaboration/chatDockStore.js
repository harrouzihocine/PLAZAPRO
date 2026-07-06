import { defineStore } from 'pinia'
import { useChatStore } from '@/features/collaboration/chatStore'
import { playChatSound } from '@/utils/notificationSound'

// Facebook-style chat dock: unread-conversation "heads" and popup threads at
// the bottom-right of every page (rendered by ChatDock in the AppShell).
// Several windows can be open side by side — each ChatDockWindow owns its
// messages and live subscription, so this store only tracks which threads are
// popped open, whether the launcher panel is expanded, and heads the user
// dismissed (a new message revives a dismissed head). Conversation data —
// inbox, unread counts, contacts — lives in the chat store.
export const useChatDockStore = defineStore('chatDock', {
  state: () => ({
    openIds: [], // popped-open conversations, newest first (rendered nearest the launcher)
    panelOpen: false,
    hiddenIds: [],
    // True on the full /chat page, where the dock steps aside: windows keep
    // their ids (they come back on leaving) but are unmounted and not "read".
    suspended: false,
  }),

  getters: {
    // Unread conversations shown as floating circles, newest activity first.
    // Popped-open threads render as windows, not heads.
    heads(state) {
      const chat = useChatStore()
      return chat.conversations
        .filter(
          (c) =>
            c.unread_count > 0 &&
            !state.openIds.includes(c.id) &&
            !state.hiddenIds.includes(c.id),
        )
        .sort((a, b) => new Date(b.last_message_at ?? 0) - new Date(a.last_message_at ?? 0))
        .slice(0, 4)
    },

    totalUnread() {
      return useChatStore().conversations.reduce((n, c) => n + (c.unread_count || 0), 0)
    },
  },

  actions: {
    open(conversationId) {
      this.panelOpen = false
      if (this.openIds.includes(conversationId)) return
      this.openIds.unshift(conversationId)
      // Keep as many side-by-side windows as the viewport fits (window ≈22rem
      // + gap, launcher column ≈88px), max 3 — the oldest drops first.
      const cap = Math.max(1, Math.min(3, Math.floor((window.innerWidth - 88) / 372)))
      this.openIds = this.openIds.slice(0, cap)
    },

    close(conversationId) {
      this.openIds = this.openIds.filter((id) => id !== conversationId)
    },

    togglePanel() {
      this.panelOpen = !this.panelOpen
      if (this.panelOpen) {
        const chat = useChatStore()
        chat.fetchConversations()
        chat.fetchContacts()
      }
    },

    dismiss(conversationId) {
      this.hiddenIds.push(conversationId)
    },

    // A chat_message notification arrived on the user's channel. Bump the inbox
    // so the head/badge appears, and pop the chat sound — unless the thread is
    // being read right now: popped open in the visible dock (the window's own
    // live handler appends + marks read) or live-subscribed on the /chat page.
    async noteIncoming(payload) {
      const chat = useChatStore()
      const id = Number(payload.subject_id)
      if (!id) return

      const readingNow =
        (!this.suspended && this.openIds.includes(id)) || chat._channelId === id
      const convo = chat.conversations.find((c) => c.id === id)
      if (convo) {
        convo.last_message_at = payload.created_at ?? new Date().toISOString()
        convo.last_message = {
          preview: payload.body || 'New message',
          created_at: convo.last_message_at,
        }
        if (!readingNow) convo.unread_count = (convo.unread_count || 0) + 1
      } else {
        // A conversation we haven't seen yet (just created) — reload the inbox;
        // the server count already includes this message.
        await chat.fetchConversations()
      }

      if (!readingNow) {
        this.hiddenIds = this.hiddenIds.filter((h) => h !== id)
        playChatSound()
      }
    },
  },
})
