<script setup>
import { computed, ref, watch } from 'vue'
import { mediaDownloadUrl, mediaFileUrl, mediaPreviewUrl } from '@/features/inventory/api'

const props = defineProps({ media: { type: Object, required: true } })
defineEmits(['close'])

const ZOOM_MIN = 1
const ZOOM_MAX = 4
const ZOOM_STEP = 0.5
const zoom = ref(1)

const isPhoto = computed(() => props.media.type === 'photo')

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

// Reset zoom when a different item is opened in the same viewer instance.
watch(() => props.media?.id, resetZoom)
</script>

<template>
  <div
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-2 backdrop-blur-sm sm:p-6"
    @click.self="$emit('close')"
  >
    <div
      class="relative flex max-h-full w-full max-w-5xl flex-col overflow-hidden rounded-xl bg-card shadow-pop"
    >
      <div class="flex items-center justify-between gap-2 border-b border-line px-4 py-2">
        <span class="truncate text-sm font-medium text-ink">{{ media.original_name }}</span>
        <div class="flex shrink-0 items-center gap-0.5">
          <!-- Zoom controls (photos only) -->
          <template v-if="isPhoto">
            <button
              class="flex min-h-[44px] min-w-[44px] items-center justify-center text-mute hover:text-ink disabled:opacity-30"
              :disabled="zoom <= ZOOM_MIN"
              aria-label="Zoom out"
              @click="zoomOut"
            >
              <i class="pi pi-search-minus" aria-hidden="true" />
            </button>
            <button
              class="num min-h-[44px] px-1 text-xs text-mute hover:text-ink"
              aria-label="Reset zoom"
              @click="resetZoom"
            >
              {{ Math.round(zoom * 100) }}%
            </button>
            <button
              class="flex min-h-[44px] min-w-[44px] items-center justify-center text-mute hover:text-ink disabled:opacity-30"
              :disabled="zoom >= ZOOM_MAX"
              aria-label="Zoom in"
              @click="zoomIn"
            >
              <i class="pi pi-search-plus" aria-hidden="true" />
            </button>
          </template>
          <a
            :href="mediaDownloadUrl(media.id)"
            :download="media.original_name"
            class="flex min-h-[44px] items-center gap-1.5 px-2 text-sm text-mute hover:text-ink"
          >
            <i class="pi pi-download" aria-hidden="true" />
            Download
          </a>
          <button
            class="flex min-h-[44px] min-w-[44px] items-center justify-center text-mute hover:text-ink"
            aria-label="Close"
            @click="$emit('close')"
          >
            <i class="pi pi-times" aria-hidden="true" />
          </button>
        </div>
      </div>

      <div
        class="flex min-h-[50vh] flex-1 items-center justify-center overflow-auto p-2"
        @wheel="onWheel"
      >
        <img
          v-if="media.type === 'photo'"
          :src="mediaFileUrl(media.id)"
          :alt="media.original_name"
          class="max-h-[80vh] max-w-full object-contain"
          :class="zoom > 1 ? 'cursor-zoom-out' : 'cursor-zoom-in'"
          :style="imgStyle"
          @click="zoom === 1 ? zoomIn() : resetZoom()"
        />

        <video
          v-else-if="media.type === 'video'"
          :src="mediaFileUrl(media.id)"
          controls
          class="max-h-[80vh] max-w-full"
        ></video>

        <iframe
          v-else-if="media.type === 'pdf'"
          :src="mediaFileUrl(media.id)"
          class="h-[80vh] w-full"
          title="Document preview"
        ></iframe>

        <!-- Office docs (presentations, Word, spreadsheets) are shown via their
             LibreOffice-rendered PDF preview; the original is available to download. -->
        <template v-else-if="['pptx', 'docx', 'xlsx'].includes(media.type)">
          <iframe
            v-if="media.preview_status === 'ready'"
            :src="mediaPreviewUrl(media.id)"
            class="h-[80vh] w-full"
            title="Document preview"
          ></iframe>
          <p v-else-if="media.preview_status === 'pending'" class="p-8 text-center opacity-70">
            Converting document for preview… check back shortly.
          </p>
          <p v-else class="p-8 text-center opacity-70">
            Preview unavailable for this file — use Download to open it.
          </p>
        </template>
      </div>
    </div>
  </div>
</template>
