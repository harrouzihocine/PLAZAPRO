<script setup>
import { onBeforeUnmount, onMounted } from 'vue'

// Generic bottom action sheet (modeled on AttachSheet): a grab handle, an
// optional title, and a stack of large touch actions. Used by the chat inbox
// long-press menu and anywhere else a native context menu is needed.
const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: null },
  // [{ key, label, icon, danger?, hint? }]
  actions: { type: Array, default: () => [] },
})
const emit = defineEmits(['close', 'pick'])

// Escape closes — also how the shell's hardware back dismisses the sheet.
function onKey(e) {
  if (e.key === 'Escape' && props.open) emit('close')
}
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
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
        class="action-sheet w-full max-w-md rounded-t-3xl bg-card px-3 pb-[max(1rem,env(safe-area-inset-bottom))] pt-2 shadow-pop"
      >
        <div class="mx-auto mb-2 h-1.5 w-10 rounded-full bg-line" aria-hidden="true" />
        <p v-if="title" class="truncate px-3 pb-2 pt-1 text-sm font-semibold text-ink">
          {{ title }}
        </p>
        <ul>
          <li v-for="a in actions" :key="a.key">
            <button
              type="button"
              class="flex w-full items-center gap-3 rounded-2xl px-4 py-3.5 text-start text-[15px] active:bg-highlight"
              :class="a.danger ? 'text-danger' : 'text-ink'"
              @click="emit('pick', a.key)"
            >
              <i v-if="a.icon" :class="a.icon" class="w-5 text-center text-lg" aria-hidden="true" />
              <span class="min-w-0 flex-1">
                {{ a.label }}
                <span v-if="a.hint" class="block truncate text-xs font-normal text-mute">{{ a.hint }}</span>
              </span>
            </button>
          </li>
        </ul>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.action-sheet {
  animation: action-sheet-up 0.22s cubic-bezier(0.22, 1, 0.36, 1);
}
@keyframes action-sheet-up {
  from {
    transform: translateY(50%);
    opacity: 0.4;
  }
  to {
    transform: translateY(0);
    opacity: 1;
  }
}
</style>
