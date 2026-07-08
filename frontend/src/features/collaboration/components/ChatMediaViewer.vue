<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

// Full-screen chat media viewer, WhatsApp-style: swipe (or arrow keys) between
// the thread's images, double-tap to zoom, download. Dependency-free — plain
// touch handlers + CSS transforms, so it works in the APK webview and on web.
const props = defineProps({
  open: { type: Boolean, default: false },
  // [{ id, url, caption? }] — the thread's image attachments, oldest first.
  items: { type: Array, default: () => [] },
  startIndex: { type: Number, default: 0 },
})
const emit = defineEmits(['close'])

const index = ref(0)
const zoomed = ref(false)
const dragX = ref(0)
let touchStartX = 0
let touchStartY = 0
let lastTap = 0

const current = computed(() => props.items[index.value] ?? null)

watch(
  () => props.open,
  (open) => {
    if (open) {
      index.value = Math.min(Math.max(props.startIndex, 0), props.items.length - 1)
      zoomed.value = false
      dragX.value = 0
    }
  },
)

function go(delta) {
  const next = index.value + delta
  if (next < 0 || next >= props.items.length) return
  index.value = next
  zoomed.value = false
}

function onTouchStart(e) {
  touchStartX = e.touches[0].clientX
  touchStartY = e.touches[0].clientY
}

function onTouchMove(e) {
  if (zoomed.value) return
  const dx = e.touches[0].clientX - touchStartX
  const dy = e.touches[0].clientY - touchStartY
  if (Math.abs(dx) > Math.abs(dy)) dragX.value = dx
}

function onTouchEnd() {
  if (Math.abs(dragX.value) > 64) go(dragX.value < 0 ? 1 : -1)
  dragX.value = 0
}

function onTap() {
  const now = Date.now()
  if (now - lastTap < 300) zoomed.value = !zoomed.value
  lastTap = now
}

function onKey(e) {
  if (!props.open) return
  if (e.key === 'Escape') emit('close')
  if (e.key === 'ArrowRight') go(1)
  if (e.key === 'ArrowLeft') go(-1)
}

onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <!-- data-gesture-surface: owns its photo-swipe — the nav drawer swipe stands
         down. data-app-overlay: hardware back closes it (via Escape) first. -->
    <div
      v-if="open && current"
      class="fixed inset-0 z-[80] flex flex-col bg-black/95"
      data-gesture-surface
      data-app-overlay
      role="dialog"
      :aria-label="$t('chat.imageViewer')"
    >
      <!-- Top bar -->
      <div
        class="flex items-center justify-between px-3 pb-2 pt-[max(0.75rem,env(safe-area-inset-top))] text-white/90"
      >
        <button
          type="button"
          class="flex h-11 w-11 items-center justify-center rounded-full active:bg-white/10"
          :aria-label="$t('common.close')"
          @click="emit('close')"
        >
          <i class="pi pi-arrow-left text-lg" aria-hidden="true" />
        </button>
        <span class="num text-sm">{{ index + 1 }} / {{ items.length }}</span>
        <a
          :href="current.url"
          download
          target="_blank"
          rel="noopener"
          class="flex h-11 w-11 items-center justify-center rounded-full active:bg-white/10"
          :aria-label="$t('common.download')"
        >
          <i class="pi pi-download text-lg" aria-hidden="true" />
        </a>
      </div>

      <!-- Stage -->
      <div
        class="relative flex min-h-0 flex-1 items-center justify-center overflow-hidden"
        @touchstart="onTouchStart"
        @touchmove="onTouchMove"
        @touchend="onTouchEnd"
        @click="onTap"
      >
        <img
          :src="current.url"
          :alt="current.caption ?? 'Shared image'"
          class="max-h-full max-w-full select-none transition-transform duration-150"
          :class="zoomed ? 'scale-[2] cursor-zoom-out' : 'cursor-zoom-in'"
          :style="dragX ? { transform: `translateX(${dragX}px)` } : undefined"
          draggable="false"
        />

        <!-- Desktop arrows -->
        <button
          v-if="index > 0"
          type="button"
          class="absolute start-2 top-1/2 hidden h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-black/40 text-white sm:flex"
          :aria-label="$t('media.previous')"
          @click.stop="go(-1)"
        >
          <i class="pi pi-chevron-left" aria-hidden="true" />
        </button>
        <button
          v-if="index < items.length - 1"
          type="button"
          class="absolute end-2 top-1/2 hidden h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-black/40 text-white sm:flex"
          :aria-label="$t('media.next')"
          @click.stop="go(1)"
        >
          <i class="pi pi-chevron-right" aria-hidden="true" />
        </button>
      </div>

      <p
        v-if="current.caption"
        class="px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-2 text-center text-sm text-white/80"
      >
        {{ current.caption }}
      </p>
      <div v-else class="pb-[env(safe-area-inset-bottom)]" />
    </div>
  </Teleport>
</template>
