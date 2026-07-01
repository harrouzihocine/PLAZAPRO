import { flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// Mock the shared Axios wrapper so the composable never hits the network.
const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@/composables/useApi', () => ({ useApi: () => ({ get }) }))

import { invalidateDynamicList, useDynamicList } from '@/composables/useDynamicList'

describe('useDynamicList', () => {
  beforeEach(() => {
    get.mockReset()
  })

  it('fetches a list once and reuses the cache across consumers', async () => {
    get.mockResolvedValue({
      data: { data: { key: 'payment_methods', items: [{ id: 1, value: 'cash' }] } },
    })

    const a = useDynamicList('payment_methods')
    await flushPromises()
    expect(a.items.value).toEqual([{ id: 1, value: 'cash' }])
    expect(get).toHaveBeenCalledTimes(1)

    // A second dropdown asking for the same list reuses the cache — no refetch.
    const b = useDynamicList('payment_methods')
    await flushPromises()
    expect(b.items.value).toEqual([{ id: 1, value: 'cash' }])
    expect(get).toHaveBeenCalledTimes(1)
  })

  it('refetches after the cache is invalidated (e.g. an admin edit)', async () => {
    get.mockResolvedValue({ data: { data: { items: [] } } })

    const list = useDynamicList('sources')
    await flushPromises()
    const before = get.mock.calls.length

    invalidateDynamicList('sources')
    list.reload()
    await flushPromises()

    expect(get.mock.calls.length).toBe(before + 1)
  })
})
