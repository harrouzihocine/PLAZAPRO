import { beforeEach, describe, expect, it } from 'vitest'
import { useTheme } from '@/composables/useTheme'

describe('useTheme', () => {
  beforeEach(() => {
    localStorage.clear()
    document.documentElement.removeAttribute('data-theme')
  })

  it('toggles between light and dark and persists the choice', () => {
    const { isNight, init, toggle } = useTheme()
    init()

    const first = document.documentElement.dataset.theme
    toggle()

    expect(document.documentElement.dataset.theme).not.toBe(first)
    expect(localStorage.getItem('plaza-theme')).toBe(isNight.value ? 'dark' : 'light')
  })

  it('restores a persisted theme on init', () => {
    localStorage.setItem('plaza-theme', 'dark')
    useTheme().init()
    expect(document.documentElement.dataset.theme).toBe('dark')
  })
})
