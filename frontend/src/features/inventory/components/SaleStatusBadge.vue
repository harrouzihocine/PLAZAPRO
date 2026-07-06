<script setup>
import { computed } from 'vue'
import StatusTag from '@/components/ui/StatusTag.vue'

// Colour-coded commercial state, reused by the units table and the stacking
// plan. Thin alias over the shared StatusTag so every page renders it the same.
// An interested unit shows how many client projects hold it ("Interested 2");
// a Reserved unit shows how many others queue behind the holder ("Reserved +1").
const props = defineProps({
  status: { type: String, default: 'available' },
  interestedCount: { type: Number, default: 0 },
})

const label = computed(() => {
  if (props.status === 'interested' && props.interestedCount > 1) {
    return `Interested ${props.interestedCount}`
  }
  if (props.status === 'reserved' && props.interestedCount > 1) {
    return `Reserved +${props.interestedCount - 1}`
  }
  return null // fall back to the shared status map label
})
</script>

<template>
  <StatusTag :value="status" :label="label" />
</template>
