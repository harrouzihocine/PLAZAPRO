import { afterEach, describe, expect, it } from 'vitest'
import { initNativeMode, isNativeApp } from '@/utils/nativeApp'

const setUserAgent = (value) =>
  Object.defineProperty(window.navigator, 'userAgent', { value, configurable: true })

describe('isNativeApp', () => {
  afterEach(() => {
    setUserAgent('Mozilla/5.0 (jsdom)')
    delete window.Capacitor
    localStorage.clear()
    document.documentElement.classList.remove('native')
  })

  it('is false in a plain browser', () => {
    expect(isNativeApp()).toBe(false)
  })

  it('detects the shell UA marker even without a Capacitor bridge (CSP-blocked)', () => {
    setUserAgent('Mozilla/5.0 (Linux; Android 14) Chrome/126 Mobile PlazaProNative/1')
    expect(isNativeApp()).toBe(true)
  })

  it('falls back to the Capacitor bridge when the UA has no marker', () => {
    window.Capacitor = { isNativePlatform: () => true }
    expect(isNativeApp()).toBe(true)
  })

  it('ignores a Capacitor bridge that reports web platform', () => {
    window.Capacitor = { isNativePlatform: () => false }
    expect(isNativeApp()).toBe(false)
  })

  it('honours the dev preview flag (dev builds only)', () => {
    localStorage.setItem('plaza-native-preview', '1')
    expect(isNativeApp()).toBe(true) // vitest runs as a dev build
  })

  it('initNativeMode stamps html.native only inside the shell', () => {
    initNativeMode()
    expect(document.documentElement.classList.contains('native')).toBe(false)

    setUserAgent('PlazaProNative/1')
    initNativeMode()
    expect(document.documentElement.classList.contains('native')).toBe(true)
  })
})
