import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const {
  conversations,
  contacts,
  createConversation,
  messages,
  sendMessage,
  markRead,
  deleteMessage,
  shareRecord,
  addParticipants,
  removeParticipant,
  react,
  toggleMute,
} = vi.hoisted(() => ({
  conversations: vi.fn(),
  contacts: vi.fn(),
  createConversation: vi.fn(),
  messages: vi.fn(),
  sendMessage: vi.fn(),
  markRead: vi.fn(),
  deleteMessage: vi.fn(),
  shareRecord: vi.fn(),
  addParticipants: vi.fn(),
  removeParticipant: vi.fn(),
  react: vi.fn(),
  toggleMute: vi.fn(),
}))
vi.mock('@/features/collaboration/api', () => ({
  chatApi: {
    conversations,
    contacts,
    createConversation,
    messages,
    sendMessage,
    markRead,
    deleteMessage,
    shareRecord,
    addParticipants,
    removeParticipant,
    react,
    toggleMute,
  },
}))
vi.mock('@/composables/useEcho', () => ({ getEcho: () => null }))
vi.mock('@/features/settings/store', () => ({
  useAuthStore: () => ({ user: { id: 1, name: 'Me' } }),
}))
// SweetAlert2 needs a real browser (matchMedia); the store only toasts.
vi.mock('@/composables/useConfirm', () => ({ toastError: vi.fn() }))
// Sends and read-marks route through the offline queue layer — mocked here.
const { queueable } = vi.hoisted(() => ({ queueable: vi.fn() }))
vi.mock('@/features/offline/apiOrQueue', () => ({ queueable }))
vi.mock('@/features/offline/snapshots', () => ({
  cacheSnapshot: vi.fn(),
  serveSnapshot: vi.fn(async () => false),
}))

import { useChatStore } from '@/features/collaboration/chatStore'

describe('chatStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('loads conversations', async () => {
    conversations.mockResolvedValue([{ id: 1, title: 'Sales', unread_count: 2 }])
    const store = useChatStore()
    await store.fetchConversations()
    expect(store.conversations).toHaveLength(1)
  })

  it('starts a direct conversation then refetches', async () => {
    createConversation.mockResolvedValue({ id: 7 })
    conversations.mockResolvedValue([{ id: 7 }])
    const store = useChatStore()
    const id = await store.startDirect(42)
    expect(createConversation).toHaveBeenCalledWith({ type: 'direct', participant_ids: [42] })
    expect(id).toBe(7)
  })

  it('sends text optimistically then swaps in the server message', async () => {
    let resolveSend
    queueable.mockReturnValue(new Promise((r) => (resolveSend = r)))
    const store = useChatStore()
    store.thread(1).loaded = true

    const promise = store.sendText(1, 'hi')
    // Optimistic clock bubble first…
    expect(store.thread(1).messages).toHaveLength(1)
    expect(store.thread(1).messages[0].pending).toBe(true)

    resolveSend({
      queued: false,
      data: { data: { id: 100, conversation_id: 1, type: 'text', body: 'hi', is_mine: true, created_at: '2026-07-07T10:00:00Z' } },
    })
    await promise
    // …replaced by the acked row.
    expect(store.thread(1).messages).toHaveLength(1)
    expect(store.thread(1).messages[0].id).toBe(100)
    expect(store.thread(1).messages[0].pending).toBeFalsy()
    // The message's client_key doubles as its idempotency key.
    expect(queueable).toHaveBeenCalledWith(
      expect.objectContaining({
        url: '/conversations/1/messages',
        uuid: store.thread(1).messages[0].client_key ?? expect.any(String),
      }),
    )
  })

  it('a queued (offline) send keeps the pending clock bubble', async () => {
    queueable.mockResolvedValue({ queued: true })
    const store = useChatStore()
    await store.sendText(1, 'hi')
    const m = store.thread(1).messages[0]
    expect(m.pending).toBe(true)
    expect(m.failed).toBe(false)
  })

  it('a failed send keeps the bubble with the failed flag for retry', async () => {
    queueable.mockRejectedValue({ response: { status: 422, data: { message: 'read-only' } } })
    const store = useChatStore()
    await store.sendText(1, 'hi')
    const m = store.thread(1).messages[0]
    expect(m.failed).toBe(true)
    expect(m.pending).toBe(false)
    // Discard removes it.
    store.discardPending(1, m.client_key)
    expect(store.thread(1).messages).toHaveLength(0)
  })

  it('receive routes by conversation id and dedupes', () => {
    const store = useChatStore()
    store.thread(2).loaded = true
    store.receive({ id: 5, conversation_id: 2, type: 'text', body: 'a', author: { id: 9 } })
    store.receive({ id: 5, conversation_id: 2, type: 'text', body: 'a', author: { id: 9 } })
    expect(store.thread(2).messages).toHaveLength(1)
    expect(store.thread(2).messages[0].is_mine).toBe(false)
    // An unloaded thread only bumps the inbox — no phantom partial threads.
    store.receive({ id: 6, conversation_id: 3, type: 'text', body: 'b', author: { id: 9 } })
    expect(store.thread(3).messages).toHaveLength(0)
  })

  it('loadOlder pages back with the before cursor and dedupes', async () => {
    const store = useChatStore()
    const t = store.thread(1)
    t.loaded = true
    t.messages = [{ id: 60, created_at: '2026-07-07' }]
    messages.mockResolvedValue([{ id: 10 }, { id: 60 }])
    const added = await store.loadOlder(1)
    expect(messages).toHaveBeenCalledWith(1, { before: 60 })
    expect(added).toBe(2)
    expect(t.messages.map((m) => m.id)).toEqual([10, 60])
    // Short page → beginning reached; further calls are no-ops.
    expect(t.oldestReached).toBe(true)
    expect(await store.loadOlder(1)).toBe(0)
  })

  it('markRead clears the badge at once and debounces the (queueable) POST', async () => {
    vi.useFakeTimers()
    queueable.mockResolvedValue({ queued: false, data: {} })
    const store = useChatStore()
    store.conversations = [{ id: 3, unread_count: 4 }]
    store.markRead(3)
    store.markRead(3)
    expect(store.conversations[0].unread_count).toBe(0)
    expect(queueable).not.toHaveBeenCalled()
    vi.advanceTimersByTime(500)
    expect(queueable).toHaveBeenCalledTimes(1)
    expect(queueable).toHaveBeenCalledWith(
      expect.objectContaining({ url: '/conversations/3/read', silent: true }),
    )
    vi.useRealTimers()
  })

  it('applyReadCursor advances a participant cursor (seen ticks)', () => {
    const store = useChatStore()
    store.conversations = [
      { id: 4, participants: [{ id: 1, last_read_at: null }, { id: 2, last_read_at: null }] },
    ]
    store.applyReadCursor({ conversation_id: 4, user_id: 2, last_read_at: '2026-07-07T10:00:00Z' })
    expect(store.conversations[0].participants[1].last_read_at).toBe('2026-07-07T10:00:00Z')
  })

  it('applyReactions regroups the flat broadcast set with mine detection', () => {
    const store = useChatStore()
    const t = store.thread(7)
    t.loaded = true
    t.messages = [{ id: 50, reactions: [] }]
    store.applyReactions({
      conversation_id: 7,
      message_id: 50,
      reactions: [
        { emoji: '👍', user_id: 1, user_name: 'Me' },
        { emoji: '👍', user_id: 2, user_name: 'Other' },
        { emoji: '❤️', user_id: 3, user_name: 'Third' },
      ],
    })
    const groups = t.messages[0].reactions
    expect(groups).toHaveLength(2)
    expect(groups[0]).toMatchObject({ emoji: '👍', count: 2, mine: true })
    expect(groups[1]).toMatchObject({ emoji: '❤️', count: 1, mine: false })
  })

  it('typing entries expire and own whispers are ignored', () => {
    const store = useChatStore()
    store.noteTyping(1, { id: 1, name: 'Me' }) // my own — ignored
    store.noteTyping(1, { id: 2, name: 'Other' })
    expect(store.typingIn(1)).toHaveLength(1)
    store.typing[1][2].until = Date.now() - 1
    expect(store.typingIn(1)).toHaveLength(0)
  })

  it('shares a record and appends it to a loaded thread', async () => {
    shareRecord.mockResolvedValue({ id: 200, type: 'system', subject: { restricted: false }, author: { id: 1 } })
    const store = useChatStore()
    store.thread(9).loaded = true
    await store.shareRecord(9, 'unit', 5)
    expect(shareRecord).toHaveBeenCalledWith(9, 'unit', 5, null)
    expect(store.thread(9).messages.some((m) => m.id === 200)).toBe(true)
  })

  it('adds participants and swaps in the updated conversation', async () => {
    addParticipants.mockResolvedValue({ id: 4, participants: [{ id: 1 }, { id: 2 }] })
    const store = useChatStore()
    store.conversations = [{ id: 4, participants: [{ id: 1 }] }]
    await store.addParticipants(4, [2])
    expect(addParticipants).toHaveBeenCalledWith(4, [2])
    expect(store.conversations[0].participants).toHaveLength(2)
  })

  it('toggleMute flips the inbox flag', async () => {
    toggleMute.mockResolvedValue({ muted: true })
    const store = useChatStore()
    store.conversations = [{ id: 4, is_muted: false }]
    await store.toggleMute(4)
    expect(store.conversations[0].is_muted).toBe(true)
  })
})
