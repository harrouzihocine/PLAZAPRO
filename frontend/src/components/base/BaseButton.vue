<script setup>
import Button from 'primevue/button'

// Thin wrapper over PrimeVue Button that keeps the historic API
// (variant: primary | ghost) so existing forms don't change.
defineProps({
  variant: { type: String, default: 'primary' }, // primary | ghost
  type: { type: String, default: 'button' },
  disabled: { type: Boolean, default: false },
})
</script>

<template>
  <Button
    :type="type"
    :disabled="disabled"
    :severity="variant === 'ghost' ? 'secondary' : null"
    :outlined="variant === 'ghost'"
  >
    <slot />
  </Button>
</template>
