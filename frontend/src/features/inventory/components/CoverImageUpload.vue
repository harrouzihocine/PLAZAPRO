<script setup>
// A single "cover" picture for a development. Uploads into the location's
// `photos` media collection (the existing private, versioned pipeline) and binds
// the returned media id as the model. After upload the image can be DRAGGED to
// reposition its focal point (stored as cover_focus_x/y %, applied as CSS
// object-position on the card + hero) — so the wanted part shows in the frame,
// without ever re-encoding the file. Needs an existing location (mediableId).
import { ref } from 'vue'
import Button from 'primevue/button'
import { mediaApi, mediaFileUrl } from '@/features/inventory/api'
import { toastError } from '@/composables/useConfirm'

const props = defineProps({
  mediableId: { type: [String, Number, null], default: null },
  modelValue: { type: [String, Number, null], default: null }, // cover_media_id
  focusX: { type: Number, default: 50 },
  focusY: { type: Number, default: 50 },
})
const emit = defineEmits(['update:modelValue', 'update:focusX', 'update:focusY'])

const fileInput = ref(null)
const frame = ref(null)
const uploading = ref(false)
const dragging = ref(false)

function pick() {
  fileInput.value?.click()
}

async function onFile(event) {
  const file = event.target.files?.[0]
  event.target.value = '' // allow re-picking the same file
  if (!file || !props.mediableId) return
  uploading.value = true
  try {
    const media = await mediaApi.upload('locations', props.mediableId, file, 'photos')
    emit('update:modelValue', media.id)
    emit('update:focusX', 50) // reset framing for the new image
    emit('update:focusY', 50)
  } catch (e) {
    toastError(e.response?.data?.message ?? 'Could not upload the cover image.')
  } finally {
    uploading.value = false
  }
}

function clearCover() {
  emit('update:modelValue', null)
  emit('update:focusX', 50)
  emit('update:focusY', 50)
}

// Drag to pan the focal point (dragging right reveals more of the LEFT, so
// object-position moves toward 0 — natural "grab and move the photo" feel).
const clamp = (v) => Math.max(0, Math.min(100, Math.round(v)))
let last = null

function start(e) {
  if (!props.modelValue) return
  dragging.value = true
  last = { x: e.clientX, y: e.clientY }
  window.addEventListener('pointermove', move)
  window.addEventListener('pointerup', end)
}
function move(e) {
  if (!dragging.value || !frame.value) return
  const rect = frame.value.getBoundingClientRect()
  const dx = ((e.clientX - last.x) / rect.width) * 100
  const dy = ((e.clientY - last.y) / rect.height) * 100
  emit('update:focusX', clamp(props.focusX - dx))
  emit('update:focusY', clamp(props.focusY - dy))
  last = { x: e.clientX, y: e.clientY }
}
function end() {
  dragging.value = false
  window.removeEventListener('pointermove', move)
  window.removeEventListener('pointerup', end)
}
</script>

<template>
  <div>
    <span class="mb-1.5 block text-sm font-medium text-ink">Cover picture</span>
    <div
      ref="frame"
      class="relative flex h-40 select-none items-center justify-center overflow-hidden rounded-xl border border-line bg-ground"
      :class="modelValue ? 'cursor-move touch-none' : ''"
      @pointerdown="start"
    >
      <img
        v-if="modelValue"
        :src="mediaFileUrl(modelValue)"
        alt="Cover"
        class="pointer-events-none h-full w-full object-cover"
        :style="{ objectPosition: `${focusX}% ${focusY}%` }"
      />
      <div v-else class="flex flex-col items-center gap-1 text-mute">
        <i class="pi pi-image text-2xl" aria-hidden="true" />
        <span class="text-xs">No cover yet</span>
      </div>

      <div
        v-if="modelValue"
        class="pointer-events-none absolute start-2 top-2 rounded bg-black/55 px-1.5 py-0.5 text-[10px] text-white"
      >
        <i class="pi pi-arrows-alt" aria-hidden="true" /> Drag to reposition
      </div>

      <div class="absolute bottom-2 end-2 flex gap-1.5" @pointerdown.stop>
        <Button
          :label="modelValue ? 'Change' : 'Upload'"
          icon="pi pi-upload"
          size="small"
          :loading="uploading"
          :disabled="!mediableId"
          @click="pick"
        />
        <Button
          v-if="modelValue"
          icon="pi pi-times"
          size="small"
          severity="secondary"
          aria-label="Remove cover"
          @click="clearCover"
        />
      </div>
    </div>
    <p v-if="!mediableId" class="mt-1 text-xs text-mute">Save the project first, then add a cover.</p>
    <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onFile" />
  </div>
</template>
