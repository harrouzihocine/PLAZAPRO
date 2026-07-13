import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import { i18n } from '@/i18n'

// jsdom ships no ResizeObserver; PrimeVue's auto-resize Textarea needs one.
globalThis.ResizeObserver ??= class {
  observe() {}
  unobserve() {}
  disconnect() {}
}

// The store is the component's only external dependency for the selection flow.
// Mock it so we control the option lists and capture the submitted payload.
const submitLead = vi.fn().mockResolvedValue(undefined)
const store = {
  desireOptions: {
    types: [
      { id: 11, label: 'Apartment', labels: { en: 'Apartment' } },
      { id: 12, label: 'Villa', labels: { en: 'Villa' } },
    ],
    room_numbers: [
      { id: 21, label: 'F2', labels: { en: 'F2' } },
      { id: 22, label: 'F3', labels: { en: 'F3' } },
      { id: 23, label: 'F4', labels: { en: 'F4' } },
    ],
    wilayas: [],
    communes: [],
  },
  loadingDesireOptions: false,
  loadDesireOptions: vi.fn(),
  submitLead,
}

vi.mock('@/features/showcase/store', () => ({ useShowcaseStore: () => store }))

import DesireForm from '@/features/showcase/components/DesireForm.vue'

const factory = () =>
  mount(DesireForm, {
    global: {
      plugins: [i18n, PrimeVue],
      stubs: { RouterLink: true },
    },
  })

/** Step 1's chip buttons, in DOM order: the type chips then the room chips. */
const chips = (wrapper) => wrapper.findAll('button[type="button"]')

describe('DesireForm — criteria chips', () => {
  it('selects and deselects a Property type chip on click (the ref-unwrap bug)', async () => {
    const wrapper = factory()
    const type = chips(wrapper)[0] // first type chip = "Apartment"

    expect(type.attributes('aria-pressed')).toBe('false')

    await type.trigger('click')
    expect(type.attributes('aria-pressed')).toBe('true')
    expect(type.classes()).toContain('bg-primary-500')

    await type.trigger('click')
    expect(type.attributes('aria-pressed')).toBe('false')
  })

  it('selects a Rooms chip independently of type chips', async () => {
    const wrapper = factory()
    const room = chips(wrapper)[2] // types are [0,1]; first room chip = index 2

    await room.trigger('click')
    expect(room.attributes('aria-pressed')).toBe('true')
    // Type chips stay untouched.
    expect(chips(wrapper)[0].attributes('aria-pressed')).toBe('false')
  })

  it('carries the selected type/room ids into the submit payload', async () => {
    const wrapper = factory()
    submitLead.mockClear()

    await chips(wrapper)[1].trigger('click') // Villa  (type id 12)
    await chips(wrapper)[3].trigger('click') // F3     (room id 22)

    // Walk the wizard to the contact step and submit.
    await wrapper.find('form').trigger('submit') // step 1 → 2
    await wrapper.find('form').trigger('submit') // step 2 → 3
    await wrapper.find('#desire-name').setValue('Sara')
    await wrapper.find('#desire-phone').setValue('0550 00 00 00')
    await wrapper.find('form').trigger('submit') // → submit()

    expect(submitLead).toHaveBeenCalledTimes(1)
    const payload = submitLead.mock.calls[0][0]
    expect(payload.type).toBe('desire')
    expect(payload.type_ids).toEqual([12])
    expect(payload.room_number_ids).toEqual([22])
  })
})
