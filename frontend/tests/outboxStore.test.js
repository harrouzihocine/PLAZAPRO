import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// In-memory stand-in for the IndexedDB outbox store.
const { db, request } = vi.hoisted(() => ({ db: new Map(), request: vi.fn() }))
vi.mock('@/features/offline/idb', () => ({
  idb: {
    getAll: vi.fn(async () => [...db.values()]),
    put: vi.fn(async (store, value) => db.set(value.uuid, value)),
    del: vi.fn(async (store, key) => db.delete(key)),
  },
}))
vi.mock('@/composables/useApi', () => ({ useApi: () => ({ request }) }))
vi.mock('@/composables/useConfirm', () => ({
  toastError: vi.fn(),
  toastSuccess: vi.fn(),
  toastInfo: vi.fn(),
}))
vi.mock('@/router', () => ({ default: { currentRoute: { value: { name: 'clients' } } } }))

import { useOutboxStore } from '@/features/offline/outboxStore'
import { useNetworkStore } from '@/features/offline/networkStore'

describe('outboxStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    db.clear()
    request.mockReset()
    useNetworkStore().online = true
  })

  async function seeded() {
    const outbox = useOutboxStore()
    await outbox.load(1)
    await outbox.enqueue({ method: 'post', url: '/a', body: { x: 1 }, label: 'First' })
    await outbox.enqueue({ method: 'post', url: '/b', body: { x: 2 }, label: 'Second' })
    return outbox
  }

  it('replays FIFO with the idempotency key and clears applied items', async () => {
    const outbox = await seeded()
    request.mockResolvedValue({ data: { ok: true } })

    await outbox.sync()

    expect(request).toHaveBeenCalledTimes(2)
    expect(request.mock.calls[0][0].url).toBe('/a')
    expect(request.mock.calls[1][0].url).toBe('/b')
    expect(request.mock.calls[0][0].headers['X-Idempotency-Key']).toBeTruthy()
    expect(outbox.items).toHaveLength(0)
    expect(db.size).toBe(0)
  })

  it('a server rejection is TERMINAL: failed with the server reason, rest continues', async () => {
    const outbox = await seeded()
    request
      .mockRejectedValueOnce({
        response: { status: 422, data: { message: 'This visit is already completed.' } },
      })
      .mockResolvedValueOnce({ data: { ok: true } })

    await outbox.sync()

    // First failed with the guard's own words; second still applied (FIFO went on).
    expect(outbox.failed).toHaveLength(1)
    expect(outbox.failed[0].lastError).toEqual({
      status: 422,
      message: 'This visit is already completed.',
    })
    expect(outbox.items).toHaveLength(1)
  })

  it('a network drop stops the loop and keeps everything pending in order', async () => {
    const outbox = await seeded()
    request.mockRejectedValue({ code: 'ERR_NETWORK' }) // no response at all

    await outbox.sync()

    expect(request).toHaveBeenCalledTimes(1) // stopped at the first item
    expect(outbox.items).toHaveLength(2)
    expect(outbox.items.every((i) => i.status === 'pending')).toBe(true)
  })

  it('a 401 pauses the queue until re-login resumes it', async () => {
    const outbox = await seeded()
    request.mockRejectedValueOnce({ response: { status: 401, data: {} } })

    await outbox.sync()
    expect(outbox.paused).toBe('auth')
    expect(outbox.items).toHaveLength(2)

    // Re-login resumes and replays.
    request.mockResolvedValue({ data: { ok: true } })
    await outbox.resumeAfterLogin()
    expect(outbox.paused).toBeNull()
  })

  it('load() filters by user and requeues interrupted syncing items', async () => {
    db.set('u1', { uuid: 'u1', userId: 1, status: 'syncing', createdAt: '2026-01-01', label: 'x', files: [] })
    db.set('u2', { uuid: 'u2', userId: 2, status: 'pending', createdAt: '2026-01-01', label: 'y', files: [] })

    const outbox = useOutboxStore()
    await outbox.load(1)

    expect(outbox.items).toHaveLength(1)
    expect(outbox.items[0].status).toBe('pending') // killed mid-sync → retry (idempotent)
  })
})
