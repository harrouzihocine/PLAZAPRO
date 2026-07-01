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
  },
}))
vi.mock('@/composables/useEcho', () => ({ getEcho: () => null }))

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

  it('sends text and appends the returned message', async () => {
    sendMessage.mockResolvedValue({ id: 100, body: 'hi', is_mine: true })
    const store = useChatStore()
    store.activeId = 1
    await store.sendText('hi')
    expect(sendMessage).toHaveBeenCalledWith(1, expect.any(FormData))
    expect(store.messages).toHaveLength(1)
    expect(store.messages[0].id).toBe(100)
  })

  it('appendMessage dedupes by id', () => {
    const store = useChatStore()
    store.appendMessage({ id: 5, body: 'a' })
    store.appendMessage({ id: 5, body: 'a' })
    expect(store.messages).toHaveLength(1)
  })

  it('markRead clears the conversation badge', async () => {
    markRead.mockResolvedValue({})
    const store = useChatStore()
    store.conversations = [{ id: 3, unread_count: 4 }]
    await store.markRead(3)
    expect(store.conversations[0].unread_count).toBe(0)
  })

  it('shares a record into the active thread and appends the card message', async () => {
    shareRecord.mockResolvedValue({ id: 200, type: 'system', subject: { restricted: false } })
    const store = useChatStore()
    store.activeId = 9
    await store.shareRecord(9, 'unit', 5)
    expect(shareRecord).toHaveBeenCalledWith(9, 'unit', 5, null)
    expect(store.messages.some((m) => m.id === 200)).toBe(true)
  })

  it('adds participants and swaps in the updated conversation', async () => {
    addParticipants.mockResolvedValue({ id: 4, participants: [{ id: 1 }, { id: 2 }] })
    const store = useChatStore()
    store.conversations = [{ id: 4, participants: [{ id: 1 }] }]
    await store.addParticipants(4, [2])
    expect(addParticipants).toHaveBeenCalledWith(4, [2])
    expect(store.conversations[0].participants).toHaveLength(2)
  })
})
