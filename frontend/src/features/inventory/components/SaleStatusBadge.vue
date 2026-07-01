<script setup>
import { computed } from 'vue'

const props = defineProps({
  status: { type: String, default: 'available' },
})

// Colour-coded commercial state, reused by the units table and the stacking plan.
const styles = {
  available: 'bg-success/15 text-success',
  reserved: 'bg-warning/15 text-warning',
  sold: 'bg-danger/15 text-danger',
}

const cls = computed(() => styles[props.status] ?? 'bg-border text-ink')
</script>

<template>
  <span class="inline-block rounded-token px-2 py-0.5 text-xs font-medium capitalize" :class="cls">
    {{ status }}
  </span>
</template>
