import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { usersList, create, update, setActive, cancel, rolesList, deptsList } = vi.hoisted(() => ({
  usersList: vi.fn(),
  create: vi.fn(),
  update: vi.fn(),
  setActive: vi.fn(),
  cancel: vi.fn(),
  rolesList: vi.fn(),
  deptsList: vi.fn(),
}))
vi.mock('@/features/settings/api', () => ({
  usersApi: { list: usersList, create, update, setActive, cancel },
  rolesApi: { list: rolesList },
  departmentsApi: { list: deptsList },
}))

import { useUsersStore } from '@/features/settings/usersStore'

describe('usersStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    for (const m of [usersList, create, update, setActive, cancel, rolesList, deptsList])
      m.mockReset()
    rolesList.mockResolvedValue([{ id: 1, name: 'Agent' }])
    deptsList.mockResolvedValue([{ id: 1, name: 'Sales' }])
  })

  it('loads users, roles and departments together', async () => {
    usersList.mockResolvedValue([{ id: 5, name: 'Sam' }])
    const store = useUsersStore()
    await store.fetch()
    expect(store.items).toEqual([{ id: 5, name: 'Sam' }])
    expect(store.roles).toEqual([{ id: 1, name: 'Agent' }])
    expect(store.departments).toEqual([{ id: 1, name: 'Sales' }])
  })

  it('sends only non-empty filters to the server', async () => {
    usersList.mockResolvedValue([])
    const store = useUsersStore()
    store.filters.role_id = 2
    store.filters.is_active = '0'
    await store.fetch()
    expect(usersList).toHaveBeenCalledWith({ role_id: 2, is_active: '0' })
  })

  it('creates then refetches from the server', async () => {
    create.mockResolvedValue({ id: 9 })
    usersList.mockResolvedValue([{ id: 9, name: 'New' }])
    const store = useUsersStore()
    await store.create({ name: 'New' })
    expect(create).toHaveBeenCalledWith({ name: 'New' })
    expect(store.items).toEqual([{ id: 9, name: 'New' }])
  })

  it('surfaces server error messages (e.g. no self-lockout)', async () => {
    cancel.mockRejectedValue({
      response: { data: { message: 'You cannot cancel your own account.' } },
    })
    const store = useUsersStore()
    await expect(store.cancel(1)).rejects.toBeTruthy()
    expect(store.error).toBe('You cannot cancel your own account.')
  })
})
