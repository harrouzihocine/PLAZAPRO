<script setup>
import { useNetworkStore } from '@/features/offline/networkStore'
import { useOutboxStore } from '@/features/offline/outboxStore'

// Slim page-wide notice under the header while the connection is down, with
// the number of queued writes waiting in the outbox. (Which SERVER we're on
// is the navbar ServerIndicator's job — a fixed banner here sat on top of
// sticky page toolbars like the dispatch board's.)
//
// OVERLAY, not in-flow: it position:fixes itself under the h-16 header (z-10,
// below the header's z-20 so it slides out from underneath). An in-flow banner
// used to shove the whole page down ~2rem every time connectivity flapped —
// the "app jumps" bug on the APK. AppShell passes lg: left offsets so it clears
// the desktop sidebar; class attrs merge onto the root below.
const network = useNetworkStore()
const outbox = useOutboxStore()
</script>

<template>
  <Transition
    enter-active-class="transition-transform duration-200 ease-out"
    enter-from-class="-translate-y-full"
    leave-active-class="transition-transform duration-150 ease-in"
    leave-to-class="-translate-y-full"
  >
    <div
      v-if="!network.online"
      class="fixed inset-x-0 top-16 z-10 flex items-center justify-center gap-2 border-b border-amber-300/60 bg-amber-100 px-3 py-1.5 text-xs font-medium text-amber-900 dark:border-amber-500/40 dark:bg-amber-950 dark:text-amber-200"
      role="status"
    >
      <i class="pi pi-wifi text-[11px] opacity-70" aria-hidden="true" />
      <span>
        {{ $t('offline.bannerTitle') }}
        {{
          outbox.pendingCount > 0
            ? $t('offline.pendingCount', outbox.pendingCount)
            : $t('offline.willSync')
        }}
      </span>
    </div>
  </Transition>
</template>
