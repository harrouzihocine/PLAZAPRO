import { computed, onBeforeUnmount, ref } from 'vue'
import { isNativeApp } from '@/utils/nativeApp'

// Reactive "phone-sized viewport" flag (below Tailwind's md breakpoint).
// Reactive because Samsung tablets and foldables cross the boundary when
// rotated or unfolded mid-session.
export function useIsPhone() {
  if (!window.matchMedia) return ref(false) // jsdom / very old webview
  const mq = window.matchMedia('(max-width: 767px)')
  const isPhone = ref(mq.matches)
  const onChange = (e) => (isPhone.value = e.matches)
  mq.addEventListener('change', onChange)
  onBeforeUnmount(() => mq.removeEventListener('change', onChange))
  return isPhone
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
