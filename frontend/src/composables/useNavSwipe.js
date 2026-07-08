import { onBeforeUnmount, onMounted } from 'vue'
import { isNativeApp } from '@/utils/nativeApp'

// Android-style swipe for the phone nav drawer, mounted once in the AppShell:
// swipe left→right anywhere on a page opens it, right→left closes it.
// Commit-at-threshold (no drag-follow animation): a clearly horizontal pull of
// TRAVEL px fires the callback once; anything vertical-ish is a scroll and is
// ceded for the rest of the touch.
const TRAVEL = 64 // horizontal px that commit the gesture
const DOMINANCE = 1.5 // horizontal travel must beat vertical by this much
const ABORT_DY = 32 // this much vertical lead = it's a scroll, stand down

export function useNavSwipe({ enabled, isOpen, open, close }) {
  let startX = 0
  let startY = 0
  let mode = 'idle' // idle | open | close

  // Overlays own their touch surface — bottom sheets, pickers, Swal, and the
  // media viewers' photo-swipe (they stamp data-gesture-surface).
  function overlayOpen() {
    return !!document.querySelector(
      '.p-dialog-mask, .p-drawer-mask, .swal2-container, .p-overlay-mask, [data-gesture-surface]',
    )
  }

  // A Sortable/vuedraggable drag (dispatch board) owns the gesture.
  function dragInProgress() {
    return !!document.querySelector(
      '.sortable-chosen, .sortable-ghost, .sortable-drag, .sortable-fallback',
    )
  }

  // Tables, tab strips and other sideways scrollers pan under the finger — a
  // swipe that starts inside one belongs to it, never to the drawer.
  function insideHorizontalScroller(target) {
    let node = target instanceof Element ? target : null
    for (; node && node !== document.body; node = node.parentElement) {
      if (node.scrollWidth > node.clientWidth + 4) {
        const { overflowX } = getComputedStyle(node)
        if (overflowX === 'auto' || overflowX === 'scroll') return true
      }
    }
    return false
  }

  function onTouchStart(e) {
    mode = 'idle'
    if (!enabled() || e.touches.length !== 1) return
    if (isOpen()) {
      mode = 'close' // swiping back left anywhere (drawer or mask) closes it
    } else {
      if (overlayOpen() || dragInProgress() || insideHorizontalScroller(e.target)) return
      mode = 'open'
    }
    startX = e.touches[0].clientX
    startY = e.touches[0].clientY
  }

  function onTouchMove(e) {
    if (mode === 'idle') return
    if (mode === 'open' && dragInProgress()) {
      mode = 'idle' // Sortable chose a card after our touchstart — cede
      return
    }
    const dx = e.touches[0].clientX - startX
    const dy = Math.abs(e.touches[0].clientY - startY)
    if (Math.abs(dx) >= TRAVEL && Math.abs(dx) >= dy * DOMINANCE) {
      // Committed horizontal — one shot per touch, right direction only.
      if (mode === 'open' && dx > 0) open()
      else if (mode === 'close' && dx < 0) close()
      mode = 'idle'
    } else if (dy > ABORT_DY && dy > Math.abs(dx)) {
      mode = 'idle'
    }
  }

  function onTouchEnd() {
    mode = 'idle'
  }

  onMounted(() => {
    if (!isNativeApp()) return
    window.addEventListener('touchstart', onTouchStart, { passive: true })
    window.addEventListener('touchmove', onTouchMove, { passive: true })
    window.addEventListener('touchend', onTouchEnd, { passive: true })
    window.addEventListener('touchcancel', onTouchEnd, { passive: true })
  })

  onBeforeUnmount(() => {
    window.removeEventListener('touchstart', onTouchStart)
    window.removeEventListener('touchmove', onTouchMove)
    window.removeEventListener('touchend', onTouchEnd)
    window.removeEventListener('touchcancel', onTouchEnd)
  })
}
