import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import BaseButton from '@/components/base/BaseButton.vue'

describe('BaseButton', () => {
  it('renders its slot and uses the themed primary classes', () => {
    const wrapper = mount(BaseButton, { slots: { default: 'Reserve' } })
    expect(wrapper.text()).toBe('Reserve')
    expect(wrapper.classes()).toContain('bg-primary')
    expect(wrapper.classes()).toContain('text-on-primary')
  })

  it('is disabled when the prop is set', () => {
    const wrapper = mount(BaseButton, { props: { disabled: true } })
    expect(wrapper.attributes('disabled')).toBeDefined()
  })
})
