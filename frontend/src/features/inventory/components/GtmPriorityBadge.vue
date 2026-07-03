<script setup>
import StatusTag from '@/components/ui/StatusTag.vue'

// Colour-coded GTM (sales) priority, reused wherever a project is shown so the
// vente team can spot what to push first. Mirrors the backend GtmPriority enum.
defineProps({
  priority: { type: String, default: 'medium' },
})
</script>

<template>
  <StatusTag :value="priority" />
</template>
