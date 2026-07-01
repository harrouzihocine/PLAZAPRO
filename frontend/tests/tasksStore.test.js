import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { list, create, complete, cancel, agentsList } = vi.hoisted(() => ({
  list: vi.fn(),
  create: vi.fn(),
  complete: vi.fn(),
  cancel: vi.fn(),
  agentsList: vi.fn(),
}))
vi.mock('@/features/pipeline/api', () => ({
  tasksApi: { list, create, complete, cancel },
}))
vi.mock('@/features/clients/api', () => ({
  agentsApi: { list: agentsList },
}))

import { useTasksStore } from '@/features/pipeline/tasksStore'

describe('tasksStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    list.mockReset()
    create.mockReset()
    complete.mockReset()
    cancel.mockReset()
    agentsList.mockReset()
    agentsList.mockResolvedValue([])
  })

  it('sends only truthy filters to the server (drops empty + false)', async () => {
    list.mockResolvedValue([])
    const store = useTasksStore()
    store.filters = { scope: 'mine', state: '', priority: 'high', overdue: false }
    await store.fetch()
    expect(list).toHaveBeenCalledWith({ scope: 'mine', priority: 'high' })
  })

  it('creates then refetches', async () => {
    create.mockResolvedValue({ id: 1 })
    list.mockResolvedValue([{ id: 1, title: 'X', state: 'open' }])
    const store = useTasksStore()
    await store.create({ title: 'X' })
    expect(create).toHaveBeenCalledWith({ title: 'X' })
    expect(store.items).toHaveLength(1)
  })

  it('surfaces a server error on create', async () => {
    create.mockRejectedValue({ response: { data: { message: 'Bad title' } } })
    const store = useTasksStore()
    await expect(store.create({ title: '' })).rejects.toBeTruthy()
    expect(store.error).toBe('Bad title')
  })
})
