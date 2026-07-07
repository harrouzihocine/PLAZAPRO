<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { runRefresh } from '@/composables/useRefreshRegistry'
import { isNativeApp } from '@/utils/nativeApp'

// Facebook-style pull-to-refresh for the APK (phones AND tablets), mounted
// once in the AppShell. The app scrolls with the window, so the gesture
// listens there: armed only at scrollY 0, rubber-band pull, spinner under the
// sticky header, then the active route's registered refresh handlers run.
// Web browsers keep their own native overscroll refresh — this never mounts.
const props = defineProps({
  // Chat thread takeover manages its own inner scroller — PTR stands down.
  disabled: { type: Boolean, default: false },
})

const route = useRoute()
const THRESHOLD = 72
const MAX = 140

const pull = ref(0)
const refreshing = ref(false)
let startY = 0
let mode = 'idle' // idle | tracking | pulling

const active = computed(() => pull.value > 0 || refreshing.value)

function overlayOpen() {
  // Dialogs, drawers and Swal own the touch surface; PTR must not fight them.
  return !!document.querySelector('.p-dialog-mask, .p-drawer-mask, .swal2-container, .p-overlay-mask')
}

// A Sortable/vuedraggable drag in progress (dispatch board or any future drag
// surface) owns the gesture — Sortable stamps these classes on the moved card.
function dragInProgress() {
  return !!document.querySelector('.sortable-chosen, .sortable-ghost, .sortable-drag, .sortable-fallback')
}

function onTouchStart(e) {
  if (refreshing.value || props.disabled || overlayOpen() || dragInProgress()) return
  if (window.scrollY > 0) return
  // A pull inside a nested scrollable area (chat list, table wrapper…) that
  // isn't itself at the top belongs to that area, not to PTR.
  const scrollable = e.target.closest?.('.overflow-y-auto, .overflow-auto')
  if (scrollable && scrollable.scrollTop > 0) return
  startY = e.touches[0].clientY
  mode = 'tracking'
}

function onTouchMove(e) {
  if (mode !== 'tracking' && mode !== 'pulling') return
  if (refreshing.value) return
  if (mode === 'tracking' && dragInProgress()) {
    // Sortable chose a card after our touchstart — cede the gesture entirely.
    mode = 'idle'
    return
  }
  const dy = e.touches[0].clientY - startY
  if (dy <= 0 || window.scrollY > 0) {
    mode = 'tracking'
    pull.value = 0
    return
  }
  mode = 'pulling'
  e.preventDefault() // the pull owns the gesture — stop the WebView bounce
  pull.value = Math.min(MAX, dy * 0.45)
}

async function onTouchEnd() {
  if (mode === 'pulling' && pull.value >= THRESHOLD) {
    refreshing.value = true
    pull.value = THRESHOLD
    try {
      await runRefresh(route.name)
    } finally {
      refreshing.value = false
      pull.value = 0
    }
  } else {
    pull.value = 0
  }
  mode = 'idle'
}

onMounted(() => {
  if (!isNativeApp()) return
  window.addEventListener('touchstart', onTouchStart, { passive: true })
  window.addEventListener('touchmove', onTouchMove, { passive: false })
  window.addEventListener('touchend', onTouchEnd, { passive: true })
  window.addEventListener('touchcancel', onTouchEnd, { passive: true })
})

onBeforeUnmount(() => {
  window.removeEventListener('touchstart', onTouchStart)
  window.removeEventListener('touchmove', onTouchMove)
  window.removeEventListener('touchend', onTouchEnd)
  window.removeEventListener('touchcancel', onTouchEnd)
})
</script>

<template>
  <!-- Indicator: a floating disc sliding out from under the sticky header. -->
  <div
    v-if="active"
    class="pointer-events-none fixed inset-x-0 top-16 z-10 flex justify-center"
    aria-hidden="true"
  >
    <div
      class="flex h-10 w-10 items-center justify-center rounded-full border border-line bg-card shadow-pop transition-transform duration-75"
      :style="{ transform: `translateY(${pull - 48}px)` }"
    >
      <i
        v-if="refreshing"
        class="pi pi-spinner pi-spin text-primary-600 dark:text-primary-400"
        aria-hidden="true"
      />
      <i
        v-else
        class="pi pi-arrow-down text-mute transition-transform"
        :class="pull >= THRESHOLD && '!text-primary-600 dark:!text-primary-400'"
        :style="{ transform: `rotate(${Math.min(180, pull * 2.5)}deg)` }"
        aria-hidden="true"
      />
    </div>
  </div>
</template>
