<script setup>
// The "you left this unfinished" banner shown inside a form whose draft was
// restored. Discard puts the form back to its clean state.
defineProps({ visible: { type: Boolean, default: false } })
const emit = defineEmits(['discard'])
</script>

<template>
  <div
    v-if="visible"
    class="flex items-center justify-between gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm dark:border-amber-500/40 dark:bg-amber-500/10"
  >
    <span class="flex items-center gap-2 text-ink">
      <i class="pi pi-history text-amber-600 dark:text-amber-400" aria-hidden="true" />
      Draft restored — you left this form unsaved.
    </span>
    <button
      type="button"
      class="shrink-0 font-medium text-amber-700 hover:underline dark:text-amber-300"
      @click="emit('discard')"
    >
      Discard draft
    </button>
  </div>
</template>
