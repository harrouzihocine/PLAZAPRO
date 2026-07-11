<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'

// Full-screen media viewer for the public gallery: keyboard + swipe nav,
// byte-range video via the public streaming endpoint, no CRM chrome.
// (A slim sibling of the CRM's MediaViewer, not a reuse — different deps.)

const props = defineProps({
  items: { type: Array, required: true },
  start: { type: Number, default: 0 },
})

const emit = defineEmits(['close'])

const index = ref(props.start)
const current = computed(() => props.items[index.value])

function prev() {
  index.value = (index.value - 1 + props.items.length) % props.items.length
}
function next() {
  index.value = (index.value + 1) % props.items.length
}

function onKey(e) {
  if (e.key === 'Escape') emit('close')
  else if (e.key === 'ArrowLeft') prev()
  else if (e.key === 'ArrowRight') next()
}

// Swipe (phones): a horizontal flick flips the slide.
let touchX = null
function onTouchStart(e) {
  touchX = e.changedTouches[0].clientX
}
function onTouchEnd(e) {
  if (touchX === null) return
  const dx = e.changedTouches[0].clientX - touchX
  touchX = null
  if (Math.abs(dx) < 48) return
  dx > 0 ? prev() : next()
}

onMounted(() => {
  window.addEventListener('keydown', onKey)
  document.body.style.overflow = 'hidden'
})
onUnmounted(() => {
  window.removeEventListener('keydown', onKey)
  document.body.style.overflow = ''
})
</script>

<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-50 flex flex-col bg-black/95"
      role="dialog"
      aria-modal="true"
      @touchstart.passive="onTouchStart"
      @touchend.passive="onTouchEnd"
    >
      <!-- Top bar -->
      <div class="flex items-center justify-between p-4 text-white">
        <span class="num text-sm text-white/70">{{ index + 1 }} / {{ items.length }}</span>
        <button
          type="button"
          class="flex h-10 w-10 items-center justify-center rounded-full bg-white/10 transition-colors hover:bg-white/20"
          :aria-label="$t('common.close')"
          @click="emit('close')"
        >
          <i class="pi pi-times" aria-hidden="true" />
        </button>
      </div>

      <!-- Stage -->
      <div class="relative flex flex-1 items-center justify-center overflow-hidden px-4 pb-6" @click.self="emit('close')">
        <img
          v-if="current.type === 'photo'"
          :key="current.id"
          :src="current.file_url"
          alt=""
          class="max-h-full max-w-full select-none rounded-lg object-contain"
          draggable="false"
        />
        <video
          v-else-if="current.type === 'video'"
          :key="current.id"
          :src="current.file_url"
          :poster="current.thumb_url ?? undefined"
          controls
          autoplay
          playsinline
          class="max-h-full max-w-full rounded-lg"
        />
        <iframe
          v-else
          :key="current.id"
          :src="current.file_url"
          class="h-full w-full rounded-lg bg-white"
          :title="$t('showcase.gallery.plans')"
        />

        <!-- Prev / next (physical sides on purpose — matches arrow keys) -->
        <template v-if="items.length > 1">
          <button
            type="button"
            class="absolute left-3 top-1/2 hidden h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20 sm:flex"
            aria-label="Previous"
            @click="prev"
          >
            <i class="pi pi-chevron-left" aria-hidden="true" />
          </button>
          <button
            type="button"
            class="absolute right-3 top-1/2 hidden h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20 sm:flex"
            aria-label="Next"
            @click="next"
          >
            <i class="pi pi-chevron-right" aria-hidden="true" />
          </button>
        </template>
      </div>
    </div>
  </Teleport>
</template>
