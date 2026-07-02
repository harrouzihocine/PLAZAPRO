import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { list, exportCsv } = vi.hoisted(() => ({ list: vi.fn(), exportCsv: vi.fn() }))
vi.mock('@/features/analytics/api', () => ({
  auditApi: { list, export: exportCsv },
}))

import { useAuditStore } from '@/features/analytics/auditStore'

describe('auditStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    list.mockReset()
    exportCsv.mockReset()
  })

  it('drops blank filters and sends the current page', async () => {
    list.mockResolvedValue({ items: [{ id: 1 }], meta: { current_page: 1, last_page: 3 } })
    const store = useAuditStore()
    store.filters = { user_id: '', action: 'create', subject_type: '', from: '', to: '' }

    await store.fetch()

    expect(list).toHaveBeenCalledWith({ action: 'create', page: 1 })
    expect(store.items).toHaveLength(1)
    expect(store.meta.last_page).toBe(3)
  })

  it('applyFilters resets to page 1 before fetching', async () => {
    list.mockResolvedValue({ items: [], meta: {} })
    const store = useAuditStore()
    store.page = 4

    await store.applyFilters()

    expect(store.page).toBe(1)
  })

  it('exportCsv passes only the active filters to the API', async () => {
    exportCsv.mockResolvedValue(new Blob(['id,created_at'], { type: 'text/csv' }))
    // jsdom lacks URL.createObjectURL — stub the download step.
    globalThis.URL.createObjectURL = vi.fn(() => 'blob:x')
    globalThis.URL.revokeObjectURL = vi.fn()

    const store = useAuditStore()
    store.filters = { user_id: '7', action: '', subject_type: '', from: '', to: '' }

    await store.exportCsv()

    expect(exportCsv).toHaveBeenCalledWith({ user_id: '7' })
  })
})
