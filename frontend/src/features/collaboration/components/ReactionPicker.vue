<script setup>
// WhatsApp-style quick-reaction bar: the six fixed emojis (mirror of the
// backend's MessageReaction::EMOJIS — the API rejects anything else).
const EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '🙏']

defineProps({
  // The viewer's current reaction (emoji string) — highlighted in the bar.
  current: { type: String, default: null },
})
const emit = defineEmits(['pick'])
</script>

<template>
  <div
    class="flex items-center gap-0.5 rounded-full border border-line bg-card px-1.5 py-1 shadow-pop"
    role="menu"
    :aria-label="$t('chat.react')"
  >
    <button
      v-for="e in EMOJIS"
      :key="e"
      type="button"
      class="flex h-9 w-9 items-center justify-center rounded-full text-xl transition-transform hover:scale-125 active:scale-110"
      :class="{ 'bg-highlight': current === e }"
      role="menuitem"
      :aria-label="`React ${e}`"
      @click.stop="emit('pick', e)"
    >
      {{ e }}
    </button>
  </div>
</template>
