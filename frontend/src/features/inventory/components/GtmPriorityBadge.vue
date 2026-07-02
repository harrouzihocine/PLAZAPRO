<script setup>
import { computed } from 'vue'

const props = defineProps({
  priority: { type: String, default: 'medium' },
})

// Colour-coded GTM (sales) priority, reused wherever a project is shown so the
// vente team can spot what to push first. Mirrors the backend GtmPriority enum.
const styles = {
  critical: 'bg-danger/15 text-danger',
  high: 'bg-warning/15 text-warning',
  medium: 'bg-border text-ink',
  low: 'opacity-60',
}

const cls = computed(() => styles[props.priority] ?? 'bg-border text-ink')
</script>

<template>
  <span class="inline-block rounded-token px-2 py-0.5 text-xs font-medium capitalize" :class="cls">
    {{ priority }}
  </span>
</template>
