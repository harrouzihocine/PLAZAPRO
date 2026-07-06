import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { list, markRead, markUnread, markAllRead } = vi.hoisted(() => ({
  list: vi.fn(),
  markRead: vi.fn(),
  markUnread: vi.fn(),
  markAllRead: vi.fn(),
}))
vi.mock('@/features/collaboration/api', () => ({
  notificationsApi: { list, markRead, markUnread, markAllRead },
}))
// Real-time is off in unit tests: getEcho() returns null so subscribe() is a no-op.
vi.mock('@/composables/useEcho', () => ({ getEcho: () => null }))

import { useNotificationsStore } from '@/features/collaboration/notificationsStore'

describe('notificationsStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    list.mockReset()
    markRead.mockReset()
    markUnread.mockReset()
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

  it('marks a single notification unread and updates the badge', async () => {
    list.mockResolvedValue({
      data: [{ id: 'a', title: 'Hi', read_at: '2026-07-05T00:00:00Z' }],
      unread_count: 0,
    })
    markUnread.mockResolvedValue({ unread_count: 1 })
    const store = useNotificationsStore()
    await store.fetch()

    await store.markUnread('a')
    expect(markUnread).toHaveBeenCalledWith('a')
    expect(store.items[0].read_at).toBeNull()
    expect(store.unreadCount).toBe(1)
  })

  it('does not call the API to mark unread when the item is already unread', async () => {
    list.mockResolvedValue({
      data: [{ id: 'a', title: 'Hi', read_at: null }],
      unread_count: 1,
    })
    const store = useNotificationsStore()
    await store.fetch()
    await store.markUnread('a')
    expect(markUnread).not.toHaveBeenCalled()
  })

  it('pushLive prepends and bumps the unread count', () => {
    const store = useNotificationsStore()
    store.pushLive({ id: 'x', title: 'Live', body: 'now' })
    expect(store.items[0].id).toBe('x')
    expect(store.unreadCount).toBe(1)
  })

  it('fetch sets pagination state from meta', async () => {
    list.mockResolvedValue({
      data: [{ id: 'a', title: 'Hi', read_at: null }],
      unread_count: 1,
      meta: { current_page: 1, last_page: 3 },
    })
    const store = useNotificationsStore()
    await store.fetch()
    expect(store.page).toBe(1)
    expect(store.lastPage).toBe(3)
    expect(store.hasMore).toBe(true)
  })

  it('loadMore appends the next page and advances the cursor', async () => {
    list
      .mockResolvedValueOnce({
        data: [{ id: 'a', title: 'First', read_at: null }],
        unread_count: 1,
        meta: { current_page: 1, last_page: 2 },
      })
      .mockResolvedValueOnce({
        data: [{ id: 'b', title: 'Second', read_at: null }],
        unread_count: 1,
        meta: { current_page: 2, last_page: 2 },
      })
    const store = useNotificationsStore()
    await store.fetch()
    await store.loadMore()

    expect(list).toHaveBeenLastCalledWith({ page: 2 })
    expect(store.items.map((n) => n.id)).toEqual(['a', 'b'])
    expect(store.hasMore).toBe(false)
  })

  it('loadMore does nothing once the last page is reached', async () => {
    list.mockResolvedValue({
      data: [{ id: 'a', title: 'Only', read_at: null }],
      unread_count: 0,
      meta: { current_page: 1, last_page: 1 },
    })
    const store = useNotificationsStore()
    await store.fetch()

    await store.loadMore()
    expect(list).toHaveBeenCalledTimes(1)
  })
})
