<script setup>
import StatusTag from '@/components/ui/StatusTag.vue'

// Colour-coded commercial state, reused by the units table and the stacking
// plan. Thin alias over the shared StatusTag so every page renders it the same.
defineProps({
  status: { type: String, default: 'available' },
})
</script>

<template>
  <StatusTag :value="status" />
</template>
