import { computed, reactive, ref } from 'vue'

// Gesture engine for the immersive media viewer: pinch-to-zoom about the
// fingers' midpoint, one-finger pan (clamped to the image), double-tap /
// double-click zoom, wheel zoom at the cursor, and the Google-Photos
// swipe-down-to-dismiss when not zoomed. Pointer-events only (mouse + touch +
// pen share one path); the caller spreads `handlers` on the stage element,
// binds `style` to the zoomable element, and must set `touch-action: none`
// on the stage so the browser hands us the moves.
//
// Callbacks: onDismiss (drag-down released past threshold), onSwipe(dir)
// (horizontal fling at scale 1 — page prev/next), onTap (chrome toggle).
export function useZoomPan({ maxScale = 5, onDismiss, onSwipe, onTap } = {}) {
  const scale = ref(1)
  const tx = ref(0)
  const ty = ref(0)
  // Dismiss drag progress 0..1 — the caller fades its backdrop with this.
  const dismissProgress = ref(0)
  // Transitions are enabled on release (spring-back), disabled while a finger
  // is down (1:1 tracking).
  const animating = ref(true)

  const zoomed = computed(() => scale.value > 1.001)
  const style = computed(() => ({
    transform: `translate3d(${tx.value}px, ${ty.value}px, 0) scale(${scale.value})`,
    transition: animating.value ? 'transform 220ms cubic-bezier(.2,.8,.25,1)' : 'none',
  }))

  // The stage (viewport) and content (rendered fit-size) boxes, measured at
  // gesture start — pan clamping keeps the image covering the viewport.
  let stageEl = null
  let contentEl = null

  const pointers = new Map()
  const gesture = reactive({ active: false })
  let start = null // snapshot at gesture start
  let lastTap = 0
  let moved = false

  function measure() {
    const stage = stageEl?.getBoundingClientRect()
    const content = contentEl?.getBoundingClientRect()
    if (!stage || !content) return null
    // Un-scaled content size (rect is post-transform).
    return {
      stage,
      w: content.width / scale.value,
      h: content.height / scale.value,
    }
  }

  function clamp() {
    const m = measure()
    if (!m) return
    const w = m.w * scale.value
    const h = m.h * scale.value
    // Pan is only meaningful along axes where the content overflows the stage;
    // otherwise it stays centered (0).
    const maxX = Math.max(0, (w - m.stage.width) / 2)
    const maxY = Math.max(0, (h - m.stage.height) / 2)
    tx.value = Math.min(maxX, Math.max(-maxX, tx.value))
    ty.value = Math.min(maxY, Math.max(-maxY, ty.value))
  }

  function reset(animate = true) {
    animating.value = animate
    scale.value = 1
    tx.value = 0
    ty.value = 0
    dismissProgress.value = 0
  }

  /** Zoom so the stage point (cx, cy) stays put — wheel/double-tap anchor. */
  function zoomAt(next, cx, cy) {
    const m = measure()
    const target = Math.min(maxScale, Math.max(1, next))
    if (!m) {
      scale.value = target
      return
    }
    const factor = target / scale.value
    // Stage-centered coordinates of the anchor point.
    const px = cx - (m.stage.left + m.stage.width / 2)
    const py = cy - (m.stage.top + m.stage.height / 2)
    tx.value = px - factor * (px - tx.value)
    ty.value = py - factor * (py - ty.value)
    scale.value = target
    if (target === 1) {
      tx.value = 0
      ty.value = 0
    } else {
      clamp()
    }
  }

  function zoomStep(delta, cx, cy) {
    animating.value = true
    const m = measure()
    zoomAt(
      scale.value + delta,
      cx ?? (m ? m.stage.left + m.stage.width / 2 : 0),
      cy ?? (m ? m.stage.top + m.stage.height / 2 : 0),
    )
  }

  function snapshot() {
    const pts = [...pointers.values()]
    start = {
      scale: scale.value,
      tx: tx.value,
      ty: ty.value,
      pts: pts.map((p) => ({ x: p.x, y: p.y })),
      dist: pts.length >= 2 ? Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y) : 0,
      mid:
        pts.length >= 2
          ? { x: (pts[0].x + pts[1].x) / 2, y: (pts[0].y + pts[1].y) / 2 }
          : { x: pts[0]?.x ?? 0, y: pts[0]?.y ?? 0 },
      t: Date.now(),
    }
  }

  function onPointerDown(e) {
    // Ignore secondary mouse buttons; let native controls (video) be.
    if (e.button !== undefined && e.button !== 0) return
    stageEl = e.currentTarget
    pointers.set(e.pointerId, { x: e.clientX, y: e.clientY })
    e.currentTarget.setPointerCapture?.(e.pointerId)
    gesture.active = true
    animating.value = false
    moved = false
    snapshot()
  }

  function onPointerMove(e) {
    if (!pointers.has(e.pointerId) || !start) return
    const p = pointers.get(e.pointerId)
    p.x = e.clientX
    p.y = e.clientY

    const pts = [...pointers.values()]
    if (pts.length >= 2) {
      // Pinch: scale about the midpoint, midpoint drag pans.
      const dist = Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y)
      const mid = { x: (pts[0].x + pts[1].x) / 2, y: (pts[0].y + pts[1].y) / 2 }
      const factor = start.dist > 0 ? dist / start.dist : 1
      const next = Math.min(maxScale, Math.max(1, start.scale * factor))
      const applied = next / start.scale
      const m = measure()
      if (m) {
        const cx = start.mid.x - (m.stage.left + m.stage.width / 2)
        const cy = start.mid.y - (m.stage.top + m.stage.height / 2)
        tx.value = cx - applied * (cx - start.tx) + (mid.x - start.mid.x)
        ty.value = cy - applied * (cy - start.ty) + (mid.y - start.mid.y)
      }
      scale.value = next
      moved = true
      dismissProgress.value = 0
      return
    }

    const dx = p.x - start.pts[0].x
    const dy = p.y - start.pts[0].y
    if (Math.abs(dx) > 4 || Math.abs(dy) > 4) moved = true

    if (zoomed.value) {
      // Pan the zoomed image 1:1.
      tx.value = start.tx + dx
      ty.value = start.ty + dy
      clamp()
    } else if (Math.abs(dy) > Math.abs(dx)) {
      // Not zoomed, vertical drag → dismiss gesture (image follows finger,
      // backdrop fades via dismissProgress).
      ty.value = dy
      dismissProgress.value = Math.min(1, Math.abs(dy) / 240)
    } else {
      // Horizontal drag at scale 1 → page swipe feedback.
      tx.value = dx
    }
  }

  function onPointerUp(e) {
    if (!pointers.has(e.pointerId)) return
    pointers.delete(e.pointerId)

    if (pointers.size > 0) {
      // One finger of a pinch lifted — rebase and keep going.
      snapshot()
      return
    }

    gesture.active = false
    animating.value = true
    const dx = e.clientX - (start?.pts[0]?.x ?? e.clientX)
    const dy = e.clientY - (start?.pts[0]?.y ?? e.clientY)
    const dt = Date.now() - (start?.t ?? 0)

    if (!zoomed.value && moved) {
      const flingX = Math.abs(dx) > 70 && Math.abs(dx) > Math.abs(dy)
      const dismiss = Math.abs(dy) > 120 && Math.abs(dy) > Math.abs(dx)
      tx.value = 0
      ty.value = 0
      dismissProgress.value = 0
      if (dismiss) return onDismiss?.()
      if (flingX) return onSwipe?.(dx < 0 ? 1 : -1)
      return
    }

    if (!moved) {
      // Tap/click: double zooms, single fires onTap (delayed past the
      // double-tap window so the two never both fire).
      const now = Date.now()
      if (now - lastTap < 300) {
        lastTap = 0
        zoomAt(zoomed.value ? 1 : 2.5, e.clientX, e.clientY)
      } else {
        lastTap = now
        setTimeout(() => {
          if (lastTap === now && dt < 500) onTap?.()
        }, 300)
      }
      return
    }

    if (zoomed.value) clamp()
    else reset()
  }

  function onDblclick(e) {
    // Mouse path (touch double-tap is handled in onPointerUp).
    animating.value = true
    zoomAt(zoomed.value ? 1 : 2.5, e.clientX, e.clientY)
  }

  function onWheel(e) {
    e.preventDefault()
    animating.value = true
    zoomAt(scale.value * (e.deltaY < 0 ? 1.25 : 0.8), e.clientX, e.clientY)
  }

  function onPointerCancel(e) {
    pointers.delete(e.pointerId)
    if (pointers.size === 0) {
      gesture.active = false
      animating.value = true
      if (!zoomed.value) reset()
    }
  }

  return {
    scale,
    zoomed,
    dismissProgress,
    style,
    gesture,
    setContentEl: (el) => (contentEl = el),
    setStageEl: (el) => (stageEl = el),
    reset,
    zoomStep,
    handlers: {
      pointerdown: onPointerDown,
      pointermove: onPointerMove,
      pointerup: onPointerUp,
      pointercancel: onPointerCancel,
      dblclick: onDblclick,
      wheel: onWheel,
    },
  }
}
