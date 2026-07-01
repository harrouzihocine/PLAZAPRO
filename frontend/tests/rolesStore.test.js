import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { rolesList, rolesCreate, rolesCancel, permsList } = vi.hoisted(() => ({
  rolesList: vi.fn(),
  rolesCreate: vi.fn(),
  rolesCancel: vi.fn(),
  permsList: vi.fn(),
}))
vi.mock('@/features/settings/api', () => ({
  rolesApi: { list: rolesList, create: rolesCreate, update: vi.fn(), cancel: rolesCancel },
  permissionsApi: { list: permsList },
}))

import { useRolesStore } from '@/features/settings/rolesStore'

describe('rolesStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    rolesList.mockReset()
    rolesCreate.mockReset()
    rolesCancel.mockReset()
    permsList.mockReset()
  })

  it('loads roles and the permission catalogue together', async () => {
    rolesList.mockResolvedValue([{ id: 1, name: 'Agent', is_agent: true, permissions: [2] }])
    permsList.mockResolvedValue([{ id: 2, name: 'View units', group: 'Inventory' }])
    const store = useRolesStore()
    await store.fetch()
    expect(store.roles).toHaveLength(1)
    expect(store.permissions).toHaveLength(1)
  })

  it('creates a role then refetches the list', async () => {
    rolesCreate.mockResolvedValue({ id: 5, name: 'Manager' })
    rolesList.mockResolvedValue([{ id: 5, name: 'Manager' }])
    const store = useRolesStore()
    await store.create({ name: 'Manager', permissions: [] })
    expect(rolesCreate).toHaveBeenCalledWith({ name: 'Manager', permissions: [] })
    expect(store.roles).toEqual([{ id: 5, name: 'Manager' }])
  })

  it('surfaces server errors (e.g. cancelling the super-admin role)', async () => {
    rolesCancel.mockRejectedValue({
      response: { data: { message: 'The Super Admin role cannot be cancelled.' } },
    })
    const store = useRolesStore()
    await expect(store.cancel(1)).rejects.toBeTruthy()
    expect(store.error).toBe('The Super Admin role cannot be cancelled.')
  })
})
