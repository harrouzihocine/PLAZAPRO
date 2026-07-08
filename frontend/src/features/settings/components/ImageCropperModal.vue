<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import Button from 'primevue/button'
import Slider from 'primevue/slider'
import BaseModal from '@/components/base/BaseModal.vue'

// A focused avatar cropper: drag the photo to reposition (both axes), zoom with
// the slider or the wheel, and "Apply" exports exactly the circular framing as
// a square JPEG. No external dependency — the visible crop is redrawn onto an
// offscreen canvas using the same transform maths as the live preview.
const props = defineProps({
  file: { type: File, required: true },
  // Output edge length in px (square). The server re-compresses again anyway.
  output: { type: Number, default: 512 },
})
const emit = defineEmits(['confirm', 'cancel'])

// Live viewport size (CSS px). Kept in sync with the actual element so the
// export maths match what the user sees on any screen width.
const VIEWPORT = ref(300)
const stage = ref(null)

const img = new Image()
const objectUrl = URL.createObjectURL(props.file)
const loaded = ref(false)
const natural = reactive({ w: 0, h: 0 })

// `baseScale` makes the image just cover the viewport at zoom = 1 (cover fit);
// `zoom` (1–4) multiplies it; `tx/ty` pan the image, in viewport px.
const zoom = ref(1)
const tx = ref(0)
const ty = ref(0)

const baseScale = computed(() =>
  natural.w && natural.h ? VIEWPORT.value / Math.min(natural.w, natural.h) : 1,
)
const displayScale = computed(() => baseScale.value * zoom.value)
const dw = computed(() => natural.w * displayScale.value)
const dh = computed(() => natural.h * displayScale.value)

// Top-left of the image relative to the viewport's top-left.
const px = computed(() => (VIEWPORT.value - dw.value) / 2 + tx.value)
const py = computed(() => (VIEWPORT.value - dh.value) / 2 + ty.value)

const imageStyle = computed(() => ({
  width: `${dw.value}px`,
  height: `${dh.value}px`,
  transform: `translate(${px.value}px, ${py.value}px)`,
}))

function clamp() {
  const maxX = Math.max(0, (dw.value - VIEWPORT.value) / 2)
  const maxY = Math.max(0, (dh.value - VIEWPORT.value) / 2)
  tx.value = Math.min(maxX, Math.max(-maxX, tx.value))
  ty.value = Math.min(maxY, Math.max(-maxY, ty.value))
}

img.onload = () => {
  natural.w = img.naturalWidth
  natural.h = img.naturalHeight
  if (stage.value) VIEWPORT.value = stage.value.clientWidth
  loaded.value = true
  clamp()
}
img.src = objectUrl

onMounted(() => {
  if (stage.value) VIEWPORT.value = stage.value.clientWidth
})
onBeforeUnmount(() => URL.revokeObjectURL(objectUrl))

// ── Drag to pan ──────────────────────────────────────────────────────────
const drag = reactive({ active: false, startX: 0, startY: 0, baseTx: 0, baseTy: 0 })

function onPointerDown(e) {
  drag.active = true
  drag.startX = e.clientX
  drag.startY = e.clientY
  drag.baseTx = tx.value
  drag.baseTy = ty.value
  e.target.setPointerCapture?.(e.pointerId)
}
function onPointerMove(e) {
  if (!drag.active) return
  tx.value = drag.baseTx + (e.clientX - drag.startX)
  ty.value = drag.baseTy + (e.clientY - drag.startY)
  clamp()
}
function onPointerUp(e) {
  drag.active = false
  e.target.releasePointerCapture?.(e.pointerId)
}

function onWheel(e) {
  const next = Math.min(4, Math.max(1, zoom.value - e.deltaY * 0.0015))
  zoom.value = Math.round(next * 100) / 100
  clamp()
}

function onZoomInput() {
  clamp()
}

// ── Export ───────────────────────────────────────────────────────────────
function apply() {
  const O = props.output
  const canvas = document.createElement('canvas')
  canvas.width = O
  canvas.height = O
  const ctx = canvas.getContext('2d')

  // Map the full viewport back to a source-image square, then paint it at O×O.
  const scale = displayScale.value
  const sx = -px.value / scale
  const sy = -py.value / scale
  const sSize = VIEWPORT.value / scale
  ctx.drawImage(img, sx, sy, sSize, sSize, 0, 0, O, O)

  canvas.toBlob(
    (blob) => {
      if (!blob) return emit('cancel')
      emit('confirm', new File([blob], 'avatar.jpg', { type: 'image/jpeg' }))
    },
    'image/jpeg',
    0.9,
  )
}
</script>

<template>
  <BaseModal :title="$t('profile.adjustPhoto')" size="max-w-md" @close="emit('cancel')">
    <div class="flex flex-col items-center gap-5">
      <!-- Stage: square, with a circular cut-out showing the exact crop. -->
      <div
        ref="stage"
        class="relative aspect-square w-full max-w-[300px] touch-none overflow-hidden rounded-lg bg-surface-900 select-none"
        :class="drag.active ? 'cursor-grabbing' : 'cursor-grab'"
        @pointerdown="onPointerDown"
        @pointermove="onPointerMove"
        @pointerup="onPointerUp"
        @pointercancel="onPointerUp"
        @wheel.prevent="onWheel"
      >
        <img
          v-show="loaded"
          :src="objectUrl"
          alt=""
          draggable="false"
          class="pointer-events-none absolute start-0 top-0 max-w-none"
          :style="imageStyle"
        />
        <!-- Circular mask + framing ring -->
        <div
          class="pointer-events-none absolute inset-0"
          style="
            box-shadow: 0 0 0 1000px rgba(0, 0, 0, 0.55);
            border-radius: 50%;
            margin: 0;
          "
        />
        <div
          class="pointer-events-none absolute inset-0 rounded-full ring-2 ring-white/70"
          aria-hidden="true"
        />
      </div>

      <!-- Zoom control -->
      <div class="flex w-full max-w-[300px] items-center gap-3">
        <i class="pi pi-image text-xs text-mute" aria-hidden="true" />
        <Slider
          v-model="zoom"
          :min="1"
          :max="4"
          :step="0.01"
          class="flex-1"
          :aria-label="$t('media.zoomIn')"
          @update:model-value="onZoomInput"
        />
        <i class="pi pi-image text-base text-mute" aria-hidden="true" />
      </div>

      <p class="text-center text-xs text-mute">Drag to reposition · scroll or use the slider to zoom.</p>

      <div class="flex w-full justify-end gap-2">
        <Button type="button" :label="$t('common.cancel')" severity="secondary" outlined @click="emit('cancel')" />
        <Button type="button" :label="$t('common.apply')" icon="pi pi-check" :disabled="!loaded" @click="apply" />
      </div>
    </div>
  </BaseModal>
</template>
