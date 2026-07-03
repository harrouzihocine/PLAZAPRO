import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PrimeVue from 'primevue/config'
import BaseButton from '@/components/base/BaseButton.vue'

const global = { plugins: [PrimeVue] }

describe('BaseButton', () => {
  it('renders its slot as a themed PrimeVue button', () => {
    const wrapper = mount(BaseButton, { global, slots: { default: 'Reserve' } })
    expect(wrapper.text()).toBe('Reserve')
    expect(wrapper.classes()).toContain('p-button')
    // Primary variant is the default look (no outlined modifier).
    expect(wrapper.classes()).not.toContain('p-button-outlined')
  })

  it('renders the ghost variant as outlined secondary', () => {
    const wrapper = mount(BaseButton, {
      global,
      props: { variant: 'ghost' },
      slots: { default: 'Cancel' },
    })
    expect(wrapper.classes()).toContain('p-button-outlined')
  })

  it('is disabled when the prop is set', () => {
    const wrapper = mount(BaseButton, { global, props: { disabled: true } })
    expect(wrapper.attributes('disabled')).toBeDefined()
  })
})
