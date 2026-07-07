<script setup>
import { useNetworkStore } from '@/features/offline/networkStore'

// Slim page-wide notice under the header while the connection is down. The
// pending-outbox count rides along once writes are queued (Sync Center).
const network = useNetworkStore()
</script>

<template>
  <Transition name="page">
    <div
      v-if="!network.online"
      class="sticky top-16 z-10 flex items-center justify-center gap-2 border-b border-amber-300/60 bg-amber-100 px-3 py-1.5 text-xs font-medium text-amber-900 dark:border-amber-500/40 dark:bg-amber-950 dark:text-amber-200"
      role="status"
    >
      <i class="pi pi-wifi text-[11px] opacity-70" aria-hidden="true" />
      You're offline — showing saved data. Changes will sync when you reconnect.
    </div>
  </Transition>
</template>
