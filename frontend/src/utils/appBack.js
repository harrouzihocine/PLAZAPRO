import { isNativeApp } from '@/utils/nativeApp'

// Facebook-style hardware back for the Android shell.
//
// MainActivity's OnBackPressedCallback calls `window.__plazaHandleBack()` and
// acts on the answer:
//   'handled' — the web consumed the press (closed an overlay, stepped the
//               router back toward the dashboard, or armed the exit hint);
//   'exit'    — second press at the root inside the window: background the
//               app (moveTaskToBack — warm reopen, like Messenger).
// Anything else (old page, boot screen) makes the shell fall back to plain
// WebView history. Shells ≤ v1.3.0 never call this, so it is inert there.

const EXIT_WINDOW_MS = 2000

// Overlays that must close before any navigation, mirroring what the Escape
// key already does: PrimeVue masks + panels, plus the app's hand-rolled
// sheets/viewers (they stamp data-app-overlay and close on window Escape).
const OVERLAY_SELECTOR = [
  '.p-dialog-mask',
  '.p-drawer-mask',
  '.p-overlay-mask',
  '.p-select-overlay',
  '.p-multiselect-overlay',
  '.p-datepicker-panel',
  '.p-popover',
  '[data-app-overlay]',
].join(', ')

function sendEscape(target) {
  target.dispatchEvent(
    new KeyboardEvent('keydown', {
      key: 'Escape',
      code: 'Escape',
      keyCode: 27,
      which: 27,
      bubbles: true,
      cancelable: true,
    }),
  )
}

export function initAppBack(router) {
  if (!isNativeApp()) return

  let armedAt = 0
  let hint = null
  let hintTimer = null

  function showExitHint() {
    if (!hint) {
      hint = document.createElement('div')
      hint.className = 'app-exit-hint'
      hint.textContent = 'Press back again to exit'
      document.body.appendChild(hint)
    }
    clearTimeout(hintTimer)
    requestAnimationFrame(() => hint.classList.add('app-exit-hint-show'))
    hintTimer = setTimeout(() => hint.classList.remove('app-exit-hint-show'), EXIT_WINDOW_MS)
  }

  function hideExitHint() {
    clearTimeout(hintTimer)
    hint?.classList.remove('app-exit-hint-show')
  }

  window.__plazaHandleBack = () => {
    // 1. A confirm/alert on top of everything: Swal listens on its own popup
    //    (toasts don't count — they never block the page).
    const swalPopup = document.querySelector('.swal2-popup:not(.swal2-toast)')
    if (swalPopup) {
      sendEscape(swalPopup)
      return 'handled'
    }

    // 2. Any other overlay: bubble an Escape up from wherever focus sits so
    //    both component-local and document-level listeners see it. A dialog
    //    that refuses Escape (deliberately non-closable) keeps the screen.
    if (document.querySelector(OVERLAY_SELECTOR)) {
      sendEscape(document.activeElement || document.body)
      return 'handled'
    }

    // 3. Not at the root: walk the in-app history — but never back INTO the
    //    login page; without usable history, land on the dashboard directly.
    const route = router.currentRoute.value
    const atRoot = route.path === '/' || route.name === 'login'
    if (!atRoot) {
      const prev = router.options.history.state?.back
      if (prev && String(prev) !== '/login') router.back()
      else router.replace('/')
      return 'handled'
    }

    // 4. At the root: first press arms and hints, a second inside the window
    //    hands the app to the launcher.
    const now = Date.now()
    if (now - armedAt < EXIT_WINDOW_MS) {
      armedAt = 0
      hideExitHint()
      return 'exit'
    }
    armedAt = now
    showExitHint()
    return 'handled'
  }
}
