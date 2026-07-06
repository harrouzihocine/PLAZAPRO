import { onBeforeUnmount, onMounted, ref } from 'vue'

// Install-to-home-screen state for the PWA install page.
//
// Chromium (Android/desktop) fires `beforeinstallprompt` when the app is
// installable; we stash the event so a button can replay it as a native
// install dialog. iOS Safari never fires it — installing there is a manual
// Share → "Add to Home Screen", so the page shows instructions instead.
export function useInstallPrompt() {
  const deferred = ref(null)
  const canInstall = ref(false)
  const installed = ref(false)

  // Already running as an installed app (home-screen icon)?
  const isStandalone =
    window.matchMedia('(display-mode: standalone)').matches ||
    window.navigator.standalone === true // iOS Safari's non-standard flag

  const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent)

  function onBeforeInstallPrompt(e) {
    e.preventDefault() // keep the mini-infobar quiet; we offer our own button
    deferred.value = e
    canInstall.value = true
  }

  function onInstalled() {
    installed.value = true
    canInstall.value = false
    deferred.value = null
  }

  async function promptInstall() {
    if (!deferred.value) return false
    deferred.value.prompt()
    const { outcome } = await deferred.value.userChoice
    if (outcome === 'accepted') onInstalled()
    return outcome === 'accepted'
  }

  onMounted(() => {
    window.addEventListener('beforeinstallprompt', onBeforeInstallPrompt)
    window.addEventListener('appinstalled', onInstalled)
  })
  onBeforeUnmount(() => {
    window.removeEventListener('beforeinstallprompt', onBeforeInstallPrompt)
    window.removeEventListener('appinstalled', onInstalled)
  })

  return { canInstall, installed, isStandalone, isIOS, promptInstall }
}
