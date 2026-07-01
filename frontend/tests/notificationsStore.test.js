import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { list, markRead, markAllRead } = vi.hoisted(() => ({
  list: vi.fn(),
  markRead: vi.fn(),
  markAllRead: vi.fn(),
}))
vi.mock('@/features/collaboration/api', () => ({
  notificationsApi: { list, markRead, markAllRead },
}))
// Real-time is off in unit tests: getEcho() returns null so subscribe() is a no-op.
vi.mock('@/composables/useEcho', () => ({ getEcho: () => null }))

import { useNotificationsStore } from '@/features/collaboration/notificationsStore'

describe('notificationsStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    list.mockReset()
    markRead.mockReset()
    markAllRead.mockReset()
  })

  it('loads notifications and the unread count', async () => {
    list.mockResolvedValue({
      data: [{ id: 'a', title: 'Hi', read_at: null }],
      unread_count: 1,
    })
    const store = useNotificationsStore()
    await store.fetch()
    expect(store.items).toHaveLength(1)
    expect(store.unreadCount).toBe(1)
  })

  it('marks a single notification read and updates the badge', async () => {
    list.mockResolvedValue({ data: [{ id: 'a', title: 'Hi', read_at: null }], unread_count: 1 })
    markRead.mockResolvedValue({ unread_count: 0 })
    const store = useNotificationsStore()
    await store.fetch()

    await store.markRead('a')
    expect(markRead).toHaveBeenCalledWith('a')
    expect(store.items[0].read_at).not.toBeNull()
    expect(store.unreadCount).toBe(0)
  })

  it('does not call the API when the item is already read', async () => {
    list.mockResolvedValue({
      data: [{ id: 'a', title: 'Hi', read_at: '2026-07-05T00:00:00Z' }],
      unread_count: 0,
    })
    const store = useNotificationsStore()
    await store.fetch()
    await store.markRead('a')
    expect(markRead).not.toHaveBeenCalled()
  })

  it('pushLive prepends and bumps the unread count', () => {
    const store = useNotificationsStore()
    store.pushLive({ id: 'x', title: 'Live', body: 'now' })
    expect(store.items[0].id).toBe('x')
    expect(store.unreadCount).toBe(1)
  })
})
