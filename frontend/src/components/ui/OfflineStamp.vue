<script setup>
import { formatDateTime } from '@/utils/format'

// "Offline — data from {time}" chip a view shows when its store served an
// IndexedDB snapshot instead of the network (store sets `offlineAt`).
defineProps({
  at: { type: String, default: null },
})
</script>

<template>
  <p
    v-if="at"
    class="mb-3 inline-flex items-center gap-1.5 rounded-full border border-amber-300/60 bg-amber-100 px-3 py-1 text-xs font-medium text-amber-900 dark:border-amber-500/40 dark:bg-amber-950 dark:text-amber-200"
    role="status"
  >
    <i class="pi pi-history text-[11px]" aria-hidden="true" />
    Offline — showing data saved {{ formatDateTime(at) }}
  </p>
</template>
