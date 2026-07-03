<script setup>
import { ref } from 'vue'
import Dialog from 'primevue/dialog'

// House modal on PrimeVue Dialog: titled, maximizable to full window, closes on
// ✕ / backdrop / Escape. Parent controls visibility with v-if and listens for
// `close` — the historic contract, unchanged.
defineProps({
  title: { type: String, default: '' },
  // Tailwind max-width class for the card.
  size: { type: String, default: 'max-w-2xl' },
  // Open at full window size — for heavy workflows like property pickers.
  fullscreen: { type: Boolean, default: false },
})
const emit = defineEmits(['close'])

const visible = ref(true)
</script>

<template>
  <Dialog
    v-model:visible="visible"
    modal
    maximizable
    dismissable-mask
    :header="title || ' '"
    :class="
      fullscreen
        ? '!m-0 !h-screen !max-h-none !w-screen !max-w-none !rounded-none'
        : ['w-[95vw]', size]
    "
    :pt="{ content: { class: 'pb-5' } }"
    @hide="emit('close')"
  >
    <slot />
  </Dialog>
</template>
