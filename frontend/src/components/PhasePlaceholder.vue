<script setup>
import BaseCard from '@/components/base/BaseCard.vue'

defineProps({
  title: { type: String, required: true },
  phase: { type: String, default: '' },
})
</script>

<template>
  <BaseCard>
    <h1 class="mb-1 text-xl font-semibold">{{ title }}</h1>
    <p class="opacity-70">Built in {{ phase }}. The Phase 0 foundation is in place.</p>
  </BaseCard>
</template>
