import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { toastInfo } from '@/composables/useConfirm'
import { useDraftsStore } from '@/features/drafts/draftsStore'

/**
 * Draft-protect a modal form: while it is open, every change is saved to the
 * drafts store; closing WITHOUT saving or cancelling keeps the draft (and the
 * top-bar drafts indicator offers the way back). Reopening restores the values
 * with a "Draft restored" banner.
 *
 *  - key      : stable identity, e.g. `call-log:42` (same key = same draft)
 *  - label    : what the drafts list shows, e.g. 'Call log — Nadia Cherif'
 *  - getForm  : () => plain serializable snapshot of the form
 *  - setForm  : (data) => apply a snapshot back onto the form
 *  - active   : optional getter — for modals whose STATE lives in the parent
 *               view (the view never unmounts on close); defaults to "mounted
 *               = open" for forms that unmount with their modal.
 *
 * The form calls `complete()` on successful submit and `discard()` on an
 * explicit Cancel (both clear the draft); `restored` drives the banner.
 */
export function useModalDraft({ key, label, getForm, setForm, active = null }) {
  const drafts = useDraftsStore()
  const route = useRoute()
  const restored = ref(false)
  const keyOf = typeof key === 'function' ? key : () => key
  let settled = false // completed or discarded — nothing left to protect
  let initial = null

  const isActive = computed(() => (active ? !!active() : true))

  function snapshot() {
    return JSON.stringify(getForm())
  }

  function open() {
    settled = false
    restored.value = false
    initial = snapshot()
    const existing = drafts.get(keyOf())
    if (existing) {
      setForm(JSON.parse(JSON.stringify(existing.data)))
      restored.value = true
    }
  }

  function close() {
    if (settled) return
    if (snapshot() === initial && !restored.value) return // untouched
    toastInfo('Draft saved — resume it from the pencil icon.')
  }

  // Persist while open (deep watch; the store debounces localStorage writes).
  watch(
    () => (isActive.value ? getForm() : null),
    (value) => {
      if (value === null || settled) return
      if (JSON.stringify(value) === initial && !restored.value) return
      drafts.save(keyOf(), {
        label,
        route: route.fullPath,
        data: JSON.parse(JSON.stringify(value)),
      })
    },
    { deep: true },
  )

  if (active) {
    watch(isActive, (open_, was) => {
      if (open_ && !was) open()
      else if (!open_ && was) close()
    })
    if (isActive.value) open()
  } else {
    open()
    onBeforeUnmount(close)
  }

  return {
    restored,
    /** The submit went through — the draft served its purpose. */
    complete() {
      settled = true
      drafts.discard(keyOf())
    },
    /** Explicit Cancel / "Discard draft": clear it and reset the form. */
    discard() {
      settled = true // suppress the reset's own watcher tick
      drafts.discard(keyOf())
      if (initial !== null) setForm(JSON.parse(initial))
      restored.value = false
      // Re-arm: edits made after a banner-discard are drafted again.
      nextTick(() => {
        initial = snapshot()
        settled = false
      })
    },
  }
}
