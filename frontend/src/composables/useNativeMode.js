import { computed, onBeforeUnmount, ref } from 'vue'
import { isNativeApp } from '@/utils/nativeApp'

// Reactive max-width flag. Reactive because Samsung tablets and foldables
// cross breakpoints when rotated or unfolded mid-session.
function useMaxWidth(px) {
  if (!window.matchMedia) return ref(false) // jsdom / very old webview
  const mq = window.matchMedia(`(max-width: ${px}px)`)
  const matches = ref(mq.matches)
  const onChange = (e) => (matches.value = e.matches)
  mq.addEventListener('change', onChange)
  onBeforeUnmount(() => mq.removeEventListener('change', onChange))
  return matches
}

// "Phone-sized" flag (below Tailwind's md breakpoint).
//
// In the APK it classifies by the SCREEN's smaller side, not the viewport
// width: the shell rotates freely, and turning a phone to landscape (width
// jumps past 768) must not flip it into the desktop layouts mid-session — a
// phone stays a phone in both orientations, a 10" tablet stays on the desktop
// layouts in both. Screen dims (unlike viewport ones) also ignore the soft
// keyboard, which halves the viewport height while typing. Foldables still
// reclassify on fold/unfold: the physical screen swaps, which fires resize.
export function useIsPhone() {
  if (isNativeApp() && window.screen?.width) {
    const phoneScreen = () => Math.min(window.screen.width, window.screen.height) < 768
    const isPhone = ref(phoneScreen())
    const onResize = () => (isPhone.value = phoneScreen())
    window.addEventListener('resize', onResize)
    onBeforeUnmount(() => window.removeEventListener('resize', onResize))
    return isPhone
  }
  return useMaxWidth(767)
}

// Below Tailwind's lg breakpoint: the desktop sidebar is hidden and navigation
// happens through the drawer (+ bottom bar) — the viewports where the nav
// drawer swipe makes sense.
export function useIsBelowLg() {
  return useMaxWidth(1023)
}

// The trigger for the app-grade mobile layouts (card lists instead of tables,
// full-bleed chat…): the APK shell on a phone-sized screen. Native tablets
// keep the desktop-style layouts — already comfortable for touch at that size.
// Pure styling stays in CSS/`native:` classes; use this only where the
// *structure* differs.
export function useNativePhone() {
  const isPhone = useIsPhone()
  const native = isNativeApp()
  return computed(() => native && isPhone.value)
}
