import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { list, create, update, cancel } = vi.hoisted(() => ({
  list: vi.fn(),
  create: vi.fn(),
  update: vi.fn(),
  cancel: vi.fn(),
}))
vi.mock('@/features/settings/api', () => ({
  departmentsApi: { list, create, update, cancel },
}))

import { useDepartmentsStore } from '@/features/settings/departmentsStore'

describe('departmentsStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    list.mockReset()
    create.mockReset()
    update.mockReset()
    cancel.mockReset()
  })

  it('loads departments', async () => {
    list.mockResolvedValue([{ id: 1, name: 'Sales' }])
    const store = useDepartmentsStore()
    await store.fetch()
    expect(store.items).toEqual([{ id: 1, name: 'Sales' }])
  })

  it('creates then refetches from the server', async () => {
    create.mockResolvedValue({ id: 2, name: 'Ops' })
    list.mockResolvedValue([{ id: 2, name: 'Ops' }])
    const store = useDepartmentsStore()
    await store.create({ name: 'Ops' })
    expect(create).toHaveBeenCalledWith({ name: 'Ops' })
    expect(store.items).toEqual([{ id: 2, name: 'Ops' }])
  })

  it('surfaces server error messages (e.g. cancel blocked by active users)', async () => {
    cancel.mockRejectedValue({ response: { data: { message: 'Reassign users first.' } } })
    const store = useDepartmentsStore()
    await expect(store.cancel(9)).rejects.toBeTruthy()
    expect(store.error).toBe('Reassign users first.')
  })
})
