<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'
import { useNetworkStore } from '@/features/offline/networkStore'
import { useOutboxStore } from '@/features/offline/outboxStore'

// TRANSITION notice, not a standing banner: slides in once the link has been
// down for a moment (blips never show it), stays a few seconds, then hands
// the persistent state to the navbar's red light (ServerIndicator). The old
// always-on banner flapped in and out on every flaky-signal transition — the
// field phones' "flashing offline toggle" complaint.
//
// OVERLAY, not in-flow: it position:fixes itself under the h-16 header (z-10,
// below the header's z-20 so it slides out from underneath). An in-flow banner
// used to shove the whole page down ~2rem every time connectivity flapped —
// the "app jumps" bug on the APK. AppShell passes lg: left offsets so it clears
// the desktop sidebar; class attrs merge onto the root below.
const SHOW_AFTER_MS = 1500 // a real outage, not a one-request blip
const HIDE_AFTER_MS = 6000 // long enough to read, then the red light owns it

const network = useNetworkStore()
const outbox = useOutboxStore()
const shown = ref(false)
let showTimer = null
let hideTimer = null

watch(
  () => network.online,
  (online) => {
    clearTimeout(showTimer)
    clearTimeout(hideTimer)
    if (online) {
      shown.value = false
      return
    }
    showTimer = setTimeout(() => {
      shown.value = true
      hideTimer = setTimeout(() => (shown.value = false), HIDE_AFTER_MS)
    }, SHOW_AFTER_MS)
  },
  { immediate: true }, // booted offline → say so once
)

onBeforeUnmount(() => {
  clearTimeout(showTimer)
  clearTimeout(hideTimer)
})
</script>

<template>
  <Transition
    enter-active-class="transition-transform duration-200 ease-out"
    enter-from-class="-translate-y-full"
    leave-active-class="transition-transform duration-150 ease-in"
    leave-to-class="-translate-y-full"
  >
    <div
      v-if="shown"
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
