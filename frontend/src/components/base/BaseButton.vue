<script setup>
defineProps({
  variant: { type: String, default: 'primary' }, // primary | ghost
  type: { type: String, default: 'button' },
  disabled: { type: Boolean, default: false },
})
</script>

<template>
  <button
    :type="type"
    :disabled="disabled"
    class="inline-flex items-center justify-center rounded-token px-4 py-2 min-h-[44px] font-medium transition disabled:opacity-50"
    :class="
      variant === 'primary'
        ? 'bg-primary text-on-primary hover:opacity-90'
        : 'bg-surface text-ink border border-border hover:opacity-90'
    "
  >
    <slot />
  </button>
</template>
