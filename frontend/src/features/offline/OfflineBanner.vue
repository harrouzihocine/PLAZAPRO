<script setup>
import { useNetworkStore } from '@/features/offline/networkStore'
import { useOutboxStore } from '@/features/offline/outboxStore'

// Slim page-wide notice under the header while the connection is down, with
// the number of queued writes waiting in the outbox.
const network = useNetworkStore()
const outbox = useOutboxStore()
</script>

<template>
  <Transition name="page">
    <div
      v-if="!network.online"
      class="sticky top-16 z-10 flex items-center justify-center gap-2 border-b border-amber-300/60 bg-amber-100 px-3 py-1.5 text-xs font-medium text-amber-900 dark:border-amber-500/40 dark:bg-amber-950 dark:text-amber-200"
      role="status"
    >
      <i class="pi pi-wifi text-[11px] opacity-70" aria-hidden="true" />
      <span>
        You're offline — showing saved data.
        {{
          outbox.pendingCount > 0
            ? `${outbox.pendingCount} change${outbox.pendingCount > 1 ? 's' : ''} waiting to sync.`
            : 'Changes will sync when you reconnect.'
        }}
      </span>
    </div>
  </Transition>
</template>
