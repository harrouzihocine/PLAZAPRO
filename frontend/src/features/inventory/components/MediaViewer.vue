<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { mediaDownloadUrl, mediaFileUrl, mediaPreviewUrl } from '@/features/inventory/api'
import { useZoomPan } from '@/composables/useZoomPan'

// Immersive media viewer (Google-Photos style): full-bleed black stage,
// gradient chrome that fades away, pinch/double-tap/wheel zoom with pan,
// swipe-down to dismiss, horizontal fling to page, bottom filmstrip, and a
// PowerPoint-like deck mode for rendered PPTX slides.
const props = defineProps({
  media: { type: Object, required: true },
  // The gallery tab's items — enables prev/next + filmstrip between them.
  items: { type: Array, default: () => [] },
})
const emit = defineEmits(['close', 'navigate'])

const root = ref(null)
const stage = ref(null)
const contentImg = ref(null)
const filmstrip = ref(null)
const entered = ref(false) // open animation

// ---- What are we showing? --------------------------------------------------
const isPhoto = computed(() => props.media.type === 'photo')
const isDeck = computed(() => props.media.type === 'pptx' && (props.media.slide_urls?.length ?? 0) > 0)
const isZoomable = computed(() => isPhoto.value || isDeck.value)

// ---- Browsing the tab ------------------------------------------------------
const index = computed(() => props.items.findIndex((i) => i.id === props.media.id))
const canNavigate = computed(() => props.items.length > 1 && index.value !== -1)

function go(delta) {
  if (!canNavigate.value) return
  const n = props.items[(index.value + delta + props.items.length) % props.items.length]
  emit('navigate', n)
}

// ---- Presentation deck (PPTX rasterized to per-slide WebP) -------------------
const slide = ref(0)
const slideCount = computed(() => props.media.slide_urls?.length ?? 0)

function goSlide(delta) {
  slide.value = Math.min(slideCount.value - 1, Math.max(0, slide.value + delta))
}

// ---- Chrome (top bar / chevrons / filmstrip) auto-hide -----------------------
const chrome = ref(true)
let chromeTimer = null

function wakeChrome() {
  chrome.value = true
  clearTimeout(chromeTimer)
  // Videos/documents keep their chrome — fading only suits the zoom stage.
  if (isZoomable.value) chromeTimer = setTimeout(() => (chrome.value = false), 3000)
}

// ---- Zoom / pan / gestures ---------------------------------------------------
const engine = useZoomPan({
  maxScale: 5,
  onDismiss: () => emit('close'),
  onSwipe: (dir) => (isDeck.value ? goSlide(dir) : go(dir)),
  onTap: () => (isDeck.value ? goSlide(1) : (chrome.value ? (chrome.value = false) : wakeChrome())),
})

// ---- Progressive load: thumbnail first, full image fades in ------------------
const loaded = ref(false)
const fullSrc = computed(() =>
  isDeck.value ? props.media.slide_urls[slide.value] : mediaFileUrl(props.media.id),
)

watch(
  fullSrc,
  (src) => {
    // Only image content warms through Image() — never video/pdf bytes.
    if (!isZoomable.value) {
      loaded.value = true
      return
    }
    loaded.value = false
    const img = new Image()
    img.onload = () => {
      if (fullSrc.value === src) loaded.value = true
    }
    img.src = src
  },
  { immediate: true },
)

// The visible source: the tiny gallery thumb (blurred up) until the real file
// is in cache. Decks have no per-slide thumbs — they show a soft spinner.
const displaySrc = computed(() => {
  if (isDeck.value || loaded.value || !props.media.thumb_url) return fullSrc.value
  return props.media.thumb_url
})

// ---- Preload neighbours so paging feels instant ------------------------------
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

// ---- Fullscreen presentation (native Fullscreen API) --------------------------
const fullscreen = ref(false)
const fullscreenSupported = !!document.documentElement.requestFullscreen

function toggleFullscreen() {
  if (document.fullscreenElement) document.exitFullscreen()
  else root.value?.requestFullscreen?.().catch(() => {})
}
function onFullscreenChange() {
  fullscreen.value = document.fullscreenElement === root.value
}

// ---- Keyboard: PowerPoint-style driving ---------------------------------------
function onKey(e) {
  wakeChrome()
  // In fullscreen the first Escape only leaves fullscreen (browser behavior);
  // the next one closes the viewer.
  if (e.key === 'Escape') return document.fullscreenElement ? undefined : emit('close')
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
  requestAnimationFrame(() => (entered.value = true))
  wakeChrome()
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  document.removeEventListener('fullscreenchange', onFullscreenChange)
  clearTimeout(chromeTimer)
})

// Reset per-item state when a different item/slide is opened.
watch(
  () => props.media?.id,
  () => {
    engine.reset(false)
    slide.value = 0
    wakeChrome()
  },
)
watch(slide, () => {
  engine.reset(false)
  wakeChrome()
  scrollFilmstrip()
})
watch(index, scrollFilmstrip)
watch(contentImg, (el) => engine.setContentEl(el))
watch(stage, (el) => engine.setStageEl(el))

// ---- Filmstrip ------------------------------------------------------------
const stripItems = computed(() => {
  if (isDeck.value) {
    return props.media.slide_urls.map((url, i) => ({ key: i, thumb: url, current: i === slide.value }))
  }
  return props.items.map((item, i) => ({
    key: item.id,
    thumb: item.thumb_url,
    icon: item.type === 'video' ? 'pi pi-video' : item.type === 'pdf' ? 'pi pi-file-pdf' : 'pi pi-file',
    current: i === index.value,
    item,
  }))
})
const showStrip = computed(() => stripItems.value.length > 1)

function onStripClick(entry) {
  if (isDeck.value) slide.value = entry.key
  else if (entry.item) emit('navigate', entry.item)
}

function scrollFilmstrip() {
  nextTick(() => {
    filmstrip.value
      ?.querySelector('[data-current="true"]')
      ?.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' })
  })
}
onMounted(scrollFilmstrip)

const counter = computed(() =>
  isDeck.value ? `${slide.value + 1} / ${slideCount.value}` : canNavigate.value ? `${index.value + 1} / ${props.items.length}` : '',
)
</script>

<template>
  <!-- data-gesture-surface: owns its gestures — the nav drawer swipe stands
       down. data-app-overlay: hardware back closes it (via Escape) first. -->
  <div
    ref="root"
    class="fixed inset-0 z-50 overflow-hidden overscroll-contain"
    data-gesture-surface
    data-app-overlay
    @pointermove="(e) => e.pointerType === 'mouse' && wakeChrome()"
  >
    <!-- Backdrop: fades with the swipe-down dismiss drag -->
    <div
      class="absolute inset-0 bg-black transition-opacity duration-200"
      :style="{ opacity: entered ? 1 - engine.dismissProgress.value * 0.6 : 0 }"
    />

    <!-- Stage -->
    <div
      ref="stage"
      class="absolute inset-0 flex items-center justify-center transition-[opacity,transform] duration-300"
      :class="[entered ? 'scale-100 opacity-100' : 'scale-[.97] opacity-0', isZoomable && 'touch-none']"
      v-on="isZoomable ? engine.handlers : {}"
      @click.self="!isZoomable && $emit('close')"
    >
      <!-- Photos + deck slides share the zoom/pan stage -->
      <template v-if="isZoomable">
        <img
          ref="contentImg"
          :src="displaySrc"
          :alt="media.original_name"
          class="max-h-full max-w-full select-none object-contain will-change-transform"
          :class="[!loaded && !isDeck && 'blur-sm', isDeck && !loaded && 'opacity-40']"
          :style="engine.style.value"
          draggable="false"
        />
        <span
          v-if="!loaded"
          class="pointer-events-none absolute inset-0 flex items-center justify-center"
          aria-hidden="true"
        >
          <i class="pi pi-spin pi-spinner text-2xl text-white/70" />
        </span>
      </template>

      <video
        v-else-if="media.type === 'video'"
        :src="mediaFileUrl(media.id)"
        :poster="media.thumb_url ?? undefined"
        controls
        autoplay
        class="max-h-full max-w-full"
      ></video>

      <iframe
        v-else-if="media.type === 'pdf'"
        :src="mediaFileUrl(media.id)"
        class="h-[92%] w-full max-w-5xl rounded-lg bg-white"
        :title="$t('media.documentPreview')"
      ></iframe>

      <!-- Office docs without slides (Word, spreadsheets, legacy decks) -->
      <template v-else-if="['pptx', 'docx', 'xlsx'].includes(media.type)">
        <iframe
          v-if="media.preview_status === 'ready'"
          :src="mediaPreviewUrl(media.id)"
          class="h-[92%] w-full max-w-5xl rounded-lg bg-white"
          :title="$t('media.documentPreview')"
        ></iframe>
        <p v-else-if="media.preview_status === 'pending'" class="p-8 text-center text-white/80">
          {{ $t('media.converting') }}
        </p>
        <p v-else class="p-8 text-center text-white/80">
          {{ $t('media.previewUnavailable') }}
        </p>
      </template>
    </div>

    <!-- Top chrome -->
    <div
      class="absolute inset-x-0 top-0 z-10 bg-gradient-to-b from-black/70 via-black/30 to-transparent pb-8 transition-opacity duration-300"
      :class="!chrome && 'pointer-events-none opacity-0'"
    >
      <div class="flex items-center gap-2 px-3 py-2 text-white">
        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-medium">{{ media.original_name }}</p>
          <p v-if="counter" class="num text-xs text-white/60">{{ counter }}</p>
        </div>

        <!-- Zoom cluster (photos + slides, pointer devices) -->
        <template v-if="isZoomable">
          <button
            class="flex h-10 w-10 items-center justify-center rounded-full text-white/90 transition-colors hover:bg-white/15 max-sm:hidden"
            :aria-label="$t('media.zoomOut')"
            @click="engine.zoomStep(-0.5)"
          >
            <i class="pi pi-search-minus" aria-hidden="true" />
          </button>
          <button
            v-if="engine.zoomed.value"
            class="num h-10 rounded-full px-2 text-xs text-white/90 transition-colors hover:bg-white/15"
            :aria-label="$t('media.resetZoom')"
            @click="engine.reset()"
          >
            {{ Math.round(engine.scale.value * 100) }}%
          </button>
          <button
            class="flex h-10 w-10 items-center justify-center rounded-full text-white/90 transition-colors hover:bg-white/15 max-sm:hidden"
            :aria-label="$t('media.zoomIn')"
            @click="engine.zoomStep(0.5)"
          >
            <i class="pi pi-search-plus" aria-hidden="true" />
          </button>
        </template>

        <button
          v-if="fullscreenSupported && (isZoomable || media.type === 'video')"
          class="flex h-10 w-10 items-center justify-center rounded-full text-white/90 transition-colors hover:bg-white/15"
          :aria-label="fullscreen ? $t('media.exitPresent') : $t('media.present')"
          @click="toggleFullscreen"
        >
          <i :class="fullscreen ? 'pi pi-window-minimize' : 'pi pi-arrows-alt'" aria-hidden="true" />
        </button>
        <a
          :href="mediaDownloadUrl(media.id)"
          :download="media.original_name"
          class="flex h-10 w-10 items-center justify-center rounded-full text-white/90 transition-colors hover:bg-white/15"
          :aria-label="$t('common.download')"
        >
          <i class="pi pi-download" aria-hidden="true" />
        </a>
        <button
          class="flex h-10 w-10 items-center justify-center rounded-full text-white/90 transition-colors hover:bg-white/15"
          :aria-label="$t('common.close')"
          @click="$emit('close')"
        >
          <i class="pi pi-times" aria-hidden="true" />
        </button>
      </div>
    </div>

    <!-- Side chevrons -->
    <template v-if="isDeck ? slideCount > 1 : canNavigate">
      <button
        class="absolute start-2 top-1/2 z-10 flex h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full border border-white/10 bg-black/35 text-white backdrop-blur-sm transition-all duration-300 hover:bg-black/60 disabled:opacity-25 max-sm:h-11 max-sm:w-11"
        :class="!chrome && 'pointer-events-none opacity-0'"
        :disabled="isDeck && slide === 0"
        :aria-label="$t('media.previous')"
        @click.stop="isDeck ? goSlide(-1) : go(-1)"
      >
        <i class="pi pi-chevron-left rtl:rotate-180" aria-hidden="true" />
      </button>
      <button
        class="absolute end-2 top-1/2 z-10 flex h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full border border-white/10 bg-black/35 text-white backdrop-blur-sm transition-all duration-300 hover:bg-black/60 disabled:opacity-25 max-sm:h-11 max-sm:w-11"
        :class="!chrome && 'pointer-events-none opacity-0'"
        :disabled="isDeck && slide === slideCount - 1"
        :aria-label="$t('media.next')"
        @click.stop="isDeck ? goSlide(1) : go(1)"
      >
        <i class="pi pi-chevron-right rtl:rotate-180" aria-hidden="true" />
      </button>
    </template>

    <!-- Bottom filmstrip -->
    <div
      v-if="showStrip"
      class="absolute inset-x-0 bottom-0 z-10 bg-gradient-to-t from-black/75 via-black/35 to-transparent pt-8 transition-opacity duration-300"
      :class="!chrome && 'pointer-events-none opacity-0'"
    >
      <div ref="filmstrip" class="flex gap-2 overflow-x-auto px-3 pb-3 pt-1 [scrollbar-width:none]">
        <button
          v-for="entry in stripItems"
          :key="entry.key"
          type="button"
          :data-current="entry.current"
          class="relative h-12 w-12 shrink-0 overflow-hidden rounded-lg bg-white/10 ring-2 transition-all duration-200 first:ms-auto last:me-auto md:h-14 md:w-14"
          :class="entry.current ? 'ring-white' : 'opacity-60 ring-transparent hover:opacity-100'"
          @click="onStripClick(entry)"
        >
          <img
            v-if="entry.thumb"
            :src="entry.thumb"
            :alt="String(entry.key)"
            loading="lazy"
            class="h-full w-full object-cover"
            draggable="false"
          />
          <span v-else class="flex h-full w-full items-center justify-center text-white/70">
            <i :class="entry.icon" aria-hidden="true" />
          </span>
        </button>
      </div>
    </div>
  </div>
</template>
