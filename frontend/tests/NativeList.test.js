import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import NativeList from '@/components/ui/NativeList.vue'
import { i18n } from '@/i18n'

const items = [
  { id: 1, name: 'One' },
  { id: 2, name: 'Two' },
]

const factory = (props = {}) =>
  mount(NativeList, {
    global: { plugins: [i18n] },
    props: { items, ...props },
    slots: { item: `<template #item="{ item }"><span>{{ item.name }}</span></template>` },
  })

describe('NativeList', () => {
  it('renders one row per item through the #item slot', () => {
    const wrapper = factory()
    const rows = wrapper.findAll('li')
    expect(rows).toHaveLength(2)
    expect(rows[1].text()).toContain('Two')
  })

  it('emits item-click only when clickable', async () => {
    const inert = factory()
    await inert.find('li').trigger('click')
    expect(inert.emitted('item-click')).toBeUndefined()

    const clickable = factory({ clickable: true })
    await clickable.find('li').trigger('click')
    expect(clickable.emitted('item-click')[0]).toEqual([items[0]])
  })

  it('pages with the DataTable event shape (0-based page + rows)', async () => {
    const wrapper = factory({ rows: 25, page: 2, total: 100 })
    expect(wrapper.text()).toContain('2 / 4')

    await wrapper.find('[aria-label="Next page"]').trigger('click')
    expect(wrapper.emitted('page')[0]).toEqual([{ page: 2, rows: 25 }]) // → page 3, 0-based 2
  })

  it('shows the empty state when there are no items', () => {
    const wrapper = mount(NativeList, { global: { plugins: [i18n] }, props: { items: [], emptyTitle: 'Nothing here' } })
    expect(wrapper.text()).toContain('Nothing here')
  })
})
