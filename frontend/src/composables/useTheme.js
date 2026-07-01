import { ref } from 'vue'

// Gold/white ↔ gold/black theme, persisted, respecting OS preference on first load.
const STORAGE_KEY = 'plaza-theme'
const isNight = ref(false)

function apply() {
  document.documentElement.dataset.theme = isNight.value ? 'dark' : 'light'
  localStorage.setItem(STORAGE_KEY, isNight.value ? 'dark' : 'light')
}

export function useTheme() {
  function init() {
    const saved = localStorage.getItem(STORAGE_KEY)
    isNight.value = saved
      ? saved === 'dark'
      : (window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false)
    apply()
  }

  function toggle() {
    isNight.value = !isNight.value
    apply()
  }

  function set(night) {
    isNight.value = Boolean(night)
    apply()
  }

  return { isNight, init, toggle, set }
}
