<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useNetworkStore } from '@/features/offline/networkStore'
import {
  updateAvailable,
  latestVersionName,
  latestVersionCode,
  dismissUpdate,
} from '@/utils/appUpdate'

// "A newer app version is out" notice for the Android shell — sideloaded APKs
// have no Play Store to nag, so the shell does it itself (utils/appUpdate.js
// does the detecting; AppShell calls initAppUpdateCheck()).
//
// Same OVERLAY rule as OfflineBanner: fixed under the h-16 header, never
// in-flow — a banner that pushes content made the app jump on flaky signal.
// Hidden while offline (you couldn't download anyway, and the offline notice
// owns that moment).
const router = useRouter()
const network = useNetworkStore()

const visible = computed(() => updateAvailable.value && network.online)

function goInstall() {
  router.push('/install')
}
</script>

<template>
  <Transition
    enter-active-class="transition-transform duration-200 ease-out"
    enter-from-class="-translate-y-full"
    leave-active-class="transition-transform duration-150 ease-in"
    leave-to-class="-translate-y-full"
  >
    <div
      v-if="visible"
      class="fixed inset-x-0 top-16 z-10 flex items-center justify-center gap-2 border-b border-primary-300/60 bg-primary-50 px-3 py-1.5 text-xs font-medium text-primary-900 dark:border-primary-500/40 dark:bg-primary-950 dark:text-primary-200"
      role="status"
    >
      <i class="pi pi-arrow-circle-up text-[11px] opacity-70" aria-hidden="true" />
      <span class="truncate">
        A new version{{ latestVersionName ? ` (v${latestVersionName})` : '' }} of the app is out.
      </span>
      <button
        type="button"
        class="shrink-0 rounded-md bg-primary px-2.5 py-1 font-semibold text-primary-contrast transition-opacity hover:opacity-90"
        @click="goInstall"
      >
        Update
      </button>
      <button
        type="button"
        class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full opacity-60 transition-opacity hover:opacity-100"
        aria-label="Dismiss update notice"
        @click="dismissUpdate(latestVersionCode())"
      >
        <i class="pi pi-times text-[10px]" aria-hidden="true" />
      </button>
    </div>
  </Transition>
</template>
