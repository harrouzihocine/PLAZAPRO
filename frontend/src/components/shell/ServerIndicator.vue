<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { serverOrigin, serverHost } from '@/utils/serverFailover'
import { isNativeApp } from '@/utils/nativeApp'
import { useNetworkStore } from '@/features/offline/networkStore'

// Which server door this session came through, as a quiet live-style icon
// next to the language switcher (replaces the old fixed banner, which sat on
// top of sticky page toolbars like the dispatch board's):
//
//   cloud, green dot     app.plaza-pro.com    — internet, through the tunnel
//   building, sky dot    office.plaza-pro.com — office LAN direct
//   building, amber dot  192.168.1.200        — LAN by bare IP (DNS down)
//
// OFFLINE takes the same slot over: red icon + red light, on every origin
// (web included). This is the PERSISTENT offline state — the OfflineBanner
// only flashes the transition and hands off to this light.
//
// Shown always inside the APK (whose serverFailover.js hops between doors on
// its own), and in browsers only on the LAN origins — web users on app.* have
// nothing unusual to flag while online. Tooltip/aria = the hostname (or the
// offline explainer), so the state has an exact answer in any language.
const KIND_STYLES = {
  app: { icon: 'pi pi-cloud', dot: 'bg-emerald-500' },
  office: { icon: 'pi pi-building', dot: 'bg-sky-500' },
  ip: { icon: 'pi pi-building', dot: 'bg-amber-500' },
}

const network = useNetworkStore()
const { t } = useI18n()

const base = KIND_STYLES[serverOrigin] ?? KIND_STYLES.app

const style = computed(() =>
  network.online ? base : { icon: 'pi pi-wifi', dot: 'bg-red-500' },
)
const visible = computed(
  () =>
    !network.online ||
    (!!KIND_STYLES[serverOrigin] && (isNativeApp() || serverOrigin !== 'app')),
)
const label = computed(() => (network.online ? serverHost : t('offline.indicator')))
</script>

<template>
  <div
    v-if="visible"
    v-tooltip.bottom="label"
    class="relative grid h-10 w-10 place-items-center"
    :class="network.online ? 'text-mute' : 'text-red-500'"
    role="status"
    :aria-label="label"
  >
    <i :class="style.icon" aria-hidden="true" />
    <span class="absolute end-1.5 top-1.5 flex h-2 w-2" aria-hidden="true">
      <span
        class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-60"
        :class="style.dot"
      />
      <span class="relative inline-flex h-2 w-2 rounded-full" :class="style.dot" />
    </span>
  </div>
</template>
