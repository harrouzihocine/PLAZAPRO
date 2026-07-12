<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { mediaDownloadUrl, mediaFileUrl, mediaPreviewUrl } from '@/features/inventory/api'

const props = defineProps({
  media: { type: Object, required: true },
  // The gallery tab's items — enables prev/next + swipe between them.
  items: { type: Array, default: () => [] },
})
const emit = defineEmits(['close', 'navigate'])

const root = ref(null)

// ---- Browsing the tab (photos/videos/documents alike) -------------------
// Messenger-style: chevrons, ←/→ and swipe move through the current tab.
const index = computed(() => props.items.findIndex((i) => i.id === props.media.id))
const canNavigate = computed(() => props.items.length > 1 && index.value !== -1)

function go(delta) {
  if (!canNavigate.value) return
  const n = props.items[(index.value + delta + props.items.length) % props.items.length]
  emit('navigate', n)
}

// ---- Presentation deck (PPTX rasterized to per-slide WebP) ---------------
const isPhoto = computed(() => props.media.type === 'photo')
const isDeck = computed(() => props.media.type === 'pptx' && (props.media.slide_urls?.length ?? 0) > 0)
const slide = ref(0)
const slideCount = computed(() => props.media.slide_urls?.length ?? 0)

function goSlide(delta) {
  slide.value = Math.min(slideCount.value - 1, Math.max(0, slide.value + delta))
}

// ---- Preload neighbours so paging feels instant ---------------------------
// (the browser caches them; slides especially must not flash white mid-deck)
function preload(urls) {
  for (const url of urls) {
    if (!url) continue
    const img = new Image()
    img.src = url
  }
}
watch(
  [() => props.media?.id, slide],
  () => {
    if (isDeck.value) {
      preload([props.media.slide_urls[slide.value + 1], props.media.slide_urls[slide.value - 1]])
    } else if (isPhoto.value && canNavigate.value) {
      const next = props.items[(index.value + 1) % props.items.length]
      const prev = props.items[(index.value - 1 + props.items.length) % props.items.length]
      preload([next?.type === 'photo' && mediaFileUrl(next.id), prev?.type === 'photo' && mediaFileUrl(prev.id)])
    }
  },
  { immediate: true },
)

// ---- Fullscreen presentation (native Fullscreen API) ----------------------
const fullscreen = ref(false)
const fullscreenSupported = !!document.documentElement.requestFullscreen

function toggleFullscreen() {
  if (document.fullscreenElement) document.exitFullscreen()
  else root.value?.requestFullscreen?.().catch(() => {})
}
function onFullscreenChange() {
  fullscreen.value = document.fullscreenElement === root.value
}

// ---- Keyboard: PowerPoint-style driving -----------------------------------
// Escape closes (also how the shell's hardware back dismisses the viewer);
// in fullscreen the browser eats the first Esc to exit fullscreen — natural.
function onKey(e) {
  if (e.key === 'Escape') return emit('close')
  if (isDeck.value) {
    if (['ArrowRight', 'ArrowDown', 'PageDown', ' ', 'Enter'].includes(e.key)) {
      e.preventDefault()
      goSlide(1)
    } else if (['ArrowLeft', 'ArrowUp', 'PageUp'].includes(e.key)) {
      e.preventDefault()
      goSlide(-1)
    } else if (e.key === 'Home') slide.value = 0
    else if (e.key === 'End') slide.value = slideCount.value - 1
  } else {
    if (e.key === 'ArrowRight') go(1)
    else if (e.key === 'ArrowLeft') go(-1)
  }
}
onMounted(() => {
  window.addEventListener('keydown', onKey)
  document.addEventListener('fullscreenchange', onFullscreenChange)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  document.removeEventListener('fullscreenchange', onFullscreenChange)
})

let touchStartX = null
function onTouchStart(e) {
  touchStartX = e.changedTouches[0]?.clientX ?? null
}
function onTouchEnd(e) {
  if (touchStartX === null) return
  const dx = (e.changedTouches[0]?.clientX ?? touchStartX) - touchStartX
  touchStartX = null
  // A zoomed photo pans instead of paging.
  if (Math.abs(dx) <= 60 || zoom.value !== 1) return
  if (isDeck.value) goSlide(dx < 0 ? 1 : -1)
  else go(dx < 0 ? 1 : -1)
}

const ZOOM_MIN = 1
const ZOOM_MAX = 4
const ZOOM_STEP = 0.5
const zoom = ref(1)

// At 1× the image fits (contain); above that we grow the real height so the
// scroll container can pan — CSS transforms don't create scrollbars.
const imgStyle = computed(() =>
  isPhoto.value && zoom.value !== 1
    ? { maxWidth: 'none', maxHeight: 'none', height: `${zoom.value * 80}vh`, width: 'auto' }
    : {},
)

const round = (n) => Math.round(n * 100) / 100
function zoomIn() {
  zoom.value = Math.min(ZOOM_MAX, round(zoom.value + ZOOM_STEP))
}
function zoomOut() {
  zoom.value = Math.max(ZOOM_MIN, round(zoom.value - ZOOM_STEP))
}
function resetZoom() {
  zoom.value = 1
}
function onWheel(e) {
  if (!isPhoto.value) return
  e.preventDefault()
  if (e.deltaY < 0) zoomIn()
  else zoomOut()
}

// Reset per-item state when a different item is opened in the same viewer.
watch(
  () => props.media?.id,
  () => {
    resetZoom()
    slide.value = 0
  },
)
</script>

<template>
  <!-- data-gesture-surface: owns its photo-swipe — the nav drawer swipe stands
       down. data-app-overlay: hardware back closes it (via Escape) first. -->
  <div
    ref="root"
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-2 backdrop-blur-sm sm:p-6"
    :class="fullscreen && 'bg-black p-0 sm:p-0'"
    data-gesture-surface
    data-app-overlay
    @click.self="$emit('close')"
  >
    <div
      class="relative flex flex-col overflow-hidden bg-card shadow-pop"
      :class="
        fullscreen
          ? 'h-full max-h-none w-full max-w-none rounded-none bg-black'
          : 'max-h-full w-full max-w-5xl rounded-xl native:max-md:h-full native:max-md:max-h-none native:max-md:rounded-none'
      "
    >
      <div
        class="flex items-center justify-between gap-2 border-b border-line px-4 py-2"
        :class="fullscreen && 'border-white/10 bg-black/60 text-white'"
      >
        <span class="truncate text-sm font-medium" :class="fullscreen ? 'text-white' : 'text-ink'">
          {{ media.original_name }}
        </span>
        <div class="flex shrink-0 items-center gap-0.5">
          <!-- Zoom controls (photos only) -->
          <template v-if="isPhoto">
            <button
              class="flex min-h-[44px] min-w-[44px] items-center justify-center text-mute hover:text-ink disabled:opacity-30"
              :disabled="zoom <= ZOOM_MIN"
              :aria-label="$t('media.zoomOut')"
              @click="zoomOut"
            >
              <i class="pi pi-search-minus" aria-hidden="true" />
            </button>
            <button
              class="num min-h-[44px] px-1 text-xs text-mute hover:text-ink"
              :aria-label="$t('media.resetZoom')"
              @click="resetZoom"
            >
              {{ Math.round(zoom * 100) }}%
            </button>
            <button
              class="flex min-h-[44px] min-w-[44px] items-center justify-center text-mute hover:text-ink disabled:opacity-30"
              :disabled="zoom >= ZOOM_MAX"
              :aria-label="$t('media.zoomIn')"
              @click="zoomIn"
            >
              <i class="pi pi-search-plus" aria-hidden="true" />
            </button>
          </template>
          <!-- Present / fullscreen (photos + decks) -->
          <button
            v-if="fullscreenSupported && (isPhoto || isDeck || media.type === 'video')"
            class="flex min-h-[44px] items-center gap-1.5 px-2 text-sm text-mute hover:text-ink"
            :aria-label="fullscreen ? $t('media.exitPresent') : $t('media.present')"
            @click="toggleFullscreen"
          >
            <i :class="fullscreen ? 'pi pi-window-minimize' : 'pi pi-play-circle'" aria-hidden="true" />
            <span class="max-sm:hidden">{{ fullscreen ? $t('media.exitPresent') : $t('media.present') }}</span>
          </button>
          <a
            :href="mediaDownloadUrl(media.id)"
            :download="media.original_name"
            class="flex min-h-[44px] items-center gap-1.5 px-2 text-sm text-mute hover:text-ink"
          >
            <i class="pi pi-download" aria-hidden="true" />
            <span class="max-sm:hidden">{{ $t('common.download') }}</span>
          </a>
          <button
            class="flex min-h-[44px] min-w-[44px] items-center justify-center text-mute hover:text-ink"
            :aria-label="$t('common.close')"
            @click="$emit('close')"
          >
            <i class="pi pi-times" aria-hidden="true" />
          </button>
        </div>
      </div>

      <!-- Prev / next through the tab's media (hidden while a deck drives its own slides) -->
      <template v-if="canNavigate && !isDeck">
        <button
          class="absolute start-1 top-1/2 z-10 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-black/40 text-white hover:bg-black/60 active:bg-black/60"
          :aria-label="$t('media.previous')"
          @click.stop="go(-1)"
        >
          <i class="pi pi-chevron-left rtl:rotate-180" aria-hidden="true" />
        </button>
        <button
          class="absolute end-1 top-1/2 z-10 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-black/40 text-white hover:bg-black/60 active:bg-black/60"
          :aria-label="$t('media.next')"
          @click.stop="go(1)"
        >
          <i class="pi pi-chevron-right rtl:rotate-180" aria-hidden="true" />
        </button>
        <span
          class="num absolute bottom-2 start-1/2 z-10 -translate-x-1/2 rounded-full bg-black/40 px-2.5 py-0.5 text-xs text-white rtl:translate-x-1/2"
        >
          {{ index + 1 }} / {{ items.length }}
        </span>
      </template>

      <!-- Slide chevrons + counter (deck mode) -->
      <template v-if="isDeck">
        <button
          class="absolute start-1 top-1/2 z-10 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-black/40 text-white hover:bg-black/60 active:bg-black/60 disabled:opacity-30"
          :disabled="slide === 0"
          :aria-label="$t('media.previous')"
          @click.stop="goSlide(-1)"
        >
          <i class="pi pi-chevron-left rtl:rotate-180" aria-hidden="true" />
        </button>
        <button
          class="absolute end-1 top-1/2 z-10 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-black/40 text-white hover:bg-black/60 active:bg-black/60 disabled:opacity-30"
          :disabled="slide === slideCount - 1"
          :aria-label="$t('media.next')"
          @click.stop="goSlide(1)"
        >
          <i class="pi pi-chevron-right rtl:rotate-180" aria-hidden="true" />
        </button>
        <span
          class="num absolute bottom-2 start-1/2 z-10 -translate-x-1/2 rounded-full bg-black/40 px-2.5 py-0.5 text-xs text-white rtl:translate-x-1/2"
        >
          {{ slide + 1 }} / {{ slideCount }}
        </span>
      </template>

      <div
        class="flex min-h-[50vh] flex-1 items-center justify-center overflow-auto p-2"
        :class="fullscreen && 'bg-black p-0'"
        @wheel="onWheel"
        @touchstart.passive="onTouchStart"
        @touchend.passive="onTouchEnd"
      >
        <img
          v-if="isPhoto"
          :src="mediaFileUrl(media.id)"
          :alt="media.original_name"
          class="max-w-full object-contain"
          :class="[zoom > 1 ? 'cursor-zoom-out' : 'cursor-zoom-in', fullscreen ? 'max-h-full' : 'max-h-[80vh]']"
          :style="imgStyle"
          @click="zoom === 1 ? zoomIn() : resetZoom()"
        />

        <video
          v-else-if="media.type === 'video'"
          :src="mediaFileUrl(media.id)"
          :poster="media.thumb_url ?? undefined"
          controls
          class="max-w-full"
          :class="fullscreen ? 'max-h-full' : 'max-h-[80vh]'"
        ></video>

        <!-- Presentation deck: pre-rendered slides, click to advance (PowerPoint style) -->
        <img
          v-else-if="isDeck"
          :src="media.slide_urls[slide]"
          :alt="`${media.original_name} — ${slide + 1}`"
          class="max-w-full cursor-pointer select-none object-contain"
          :class="fullscreen ? 'max-h-full' : 'max-h-[80vh]'"
          @click="goSlide(1)"
        />

        <iframe
          v-else-if="media.type === 'pdf'"
          :src="mediaFileUrl(media.id)"
          class="h-[80vh] w-full"
          :title="$t('media.documentPreview')"
        ></iframe>

        <!-- Office docs without slides (Word, spreadsheets, legacy decks) are
             shown via their LibreOffice-rendered PDF; the original stays downloadable. -->
        <template v-else-if="['pptx', 'docx', 'xlsx'].includes(media.type)">
          <iframe
            v-if="media.preview_status === 'ready'"
            :src="mediaPreviewUrl(media.id)"
            class="h-[80vh] w-full"
            :title="$t('media.documentPreview')"
          ></iframe>
          <p v-else-if="media.preview_status === 'pending'" class="p-8 text-center opacity-70">
            {{ $t('media.converting') }}
          </p>
          <p v-else class="p-8 text-center opacity-70">
            {{ $t('media.previewUnavailable') }}
          </p>
        </template>
      </div>
    </div>
  </div>
</template>
