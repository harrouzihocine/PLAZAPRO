<script setup>
// WhatsApp-style attach sheet for the Android shell: instead of dumping the
// user straight into the phone's file manager, offer the sources as big
// colored circles — camera, video, photo & video library, document. The
// parent wires each `pick` kind to a hidden <input type="file"> (capture=
// opens the camera app; image/video accepts open Android's photo-picker grid,
// which IS the Messenger-style multi-select).
import { computed, onBeforeUnmount, onMounted } from 'vue'

const props = defineProps({
  open: { type: Boolean, default: false },
  // Which sources to offer (subset of SOURCES kinds); null = all of them.
  // Chat passes no camera-video: its backend treats video containers as
  // voice notes (MediaRecorder compatibility), so real video stays out.
  kinds: { type: Array, default: null },
})
const emit = defineEmits(['close', 'pick'])

// Escape closes — also how the shell's hardware back dismisses the sheet.
function onKey(e) {
  if (e.key === 'Escape' && props.open) emit('close')
}
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

const SOURCES = [
  { kind: 'camera-photo', label: 'Camera', icon: 'pi pi-camera', bg: 'bg-rose-500' },
  { kind: 'camera-video', label: 'Video', icon: 'pi pi-video', bg: 'bg-purple-500' },
  { kind: 'library', label: 'Gallery', icon: 'pi pi-images', bg: 'bg-emerald-500' },
  { kind: 'document', label: 'Document', icon: 'pi pi-file', bg: 'bg-sky-500' },
]
const shown = computed(() =>
  props.kinds ? SOURCES.filter((s) => props.kinds.includes(s.kind)) : SOURCES,
)
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-[70] flex items-end justify-center bg-black/40"
      data-app-overlay
      @click.self="emit('close')"
    >
      <div
        class="attach-sheet w-full max-w-md rounded-t-3xl bg-card px-4 pb-[max(1.25rem,env(safe-area-inset-bottom))] pt-2 shadow-pop"
      >
        <div class="mx-auto mb-4 h-1.5 w-10 rounded-full bg-line" aria-hidden="true" />
        <div class="grid grid-cols-4 gap-2">
          <button
            v-for="s in shown"
            :key="s.kind"
            type="button"
            class="flex flex-col items-center gap-2 rounded-2xl py-3 active:bg-highlight"
            @click="emit('pick', s.kind)"
          >
            <span
              class="flex h-14 w-14 items-center justify-center rounded-full text-white"
              :class="s.bg"
            >
              <i :class="s.icon" class="text-xl" aria-hidden="true" />
            </span>
            <span class="text-xs text-ink">{{ s.label }}</span>
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.attach-sheet {
  animation: attach-sheet-up 0.25s cubic-bezier(0.22, 1, 0.36, 1);
}
@keyframes attach-sheet-up {
  from {
    transform: translateY(60%);
    opacity: 0.4;
  }
  to {
    transform: translateY(0);
    opacity: 1;
  }
}
</style>
