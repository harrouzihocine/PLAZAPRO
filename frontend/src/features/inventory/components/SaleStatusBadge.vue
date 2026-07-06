<script setup>
import { computed } from 'vue'
import StatusTag from '@/components/ui/StatusTag.vue'

// Colour-coded commercial state, reused by the units table and the stacking
// plan. Thin alias over the shared StatusTag so every page renders it the same.
// A reserved unit shows how many client projects hold it ("Reserved 2"); an
// On Hold unit shows how many others queue behind the holder ("On hold +1").
const props = defineProps({
  status: { type: String, default: 'available' },
  reservedCount: { type: Number, default: 0 },
})

const label = computed(() => {
  if (props.status === 'reserved' && props.reservedCount > 1) {
    return `Reserved ${props.reservedCount}`
  }
  if (props.status === 'onhold' && props.reservedCount > 1) {
    return `On hold +${props.reservedCount - 1}`
  }
  return null // fall back to the shared status map label
})
</script>

<template>
  <StatusTag :value="status" :label="label" />
</template>
