<script setup>
// Friendly empty/error placeholder used by every list and panel.
defineProps({
  icon: { type: String, default: 'pi pi-inbox' },
  title: { type: String, required: true },
  body: { type: String, default: null },
})
</script>

<template>
  <div class="flex flex-col items-center justify-center px-6 py-12 text-center">
    <span
      class="flex h-12 w-12 items-center justify-center rounded-full bg-surface-100 dark:bg-surface-800"
    >
      <i :class="icon" class="text-xl text-mute" aria-hidden="true" />
    </span>
    <p class="mt-3 text-sm font-semibold text-ink">{{ title }}</p>
    <p v-if="body" class="mt-1 max-w-sm text-sm text-mute">{{ body }}</p>
    <div v-if="$slots.default" class="mt-4">
      <slot />
    </div>
  </div>
</template>
