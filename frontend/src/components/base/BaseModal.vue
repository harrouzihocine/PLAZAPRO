<script setup>
import { onBeforeUnmount, onMounted } from 'vue'

// House modal: teleported overlay with a titled card. Used by the client-file
// workflow (log call, complete visit, open deal, …) so heavy forms don't pile up
// inline. Close = ✕, backdrop click or Escape. Parent controls visibility with
// v-if and listens for `close`.
defineProps({
  title: { type: String, default: '' },
  // Tailwind max-width class for the card.
  size: { type: String, default: 'max-w-2xl' },
})
const emit = defineEmits(['close'])

function onKeydown(e) {
  if (e.key === 'Escape') emit('close')
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4 sm:items-center" @click.self="emit('close')">
      <div class="my-4 w-full rounded-token border border-border bg-surface shadow-xl" :class="size">
        <div class="flex items-center justify-between border-b border-border px-4 py-3">
          <h2 class="text-sm font-semibold uppercase opacity-70">{{ title }}</h2>
          <button type="button" class="rounded px-2 text-lg leading-none opacity-60 hover:opacity-100" aria-label="Close" @click="emit('close')">
            ✕
          </button>
        </div>
        <div class="max-h-[80vh] overflow-y-auto p-4">
          <slot />
        </div>
      </div>
    </div>
  </Teleport>
</template>
