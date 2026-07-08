<script setup>
import { computed, onMounted, ref } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import { useInstallPrompt } from '@/composables/useInstallPrompt'
import { installedAppVersion } from '@/utils/appUpdate'
import { isNativeApp } from '@/utils/nativeApp'

// The "Mobile App" page every user can open from the navbar. Android staff get
// the dedicated app: a signed APK downloaded straight from this server
// (sideload — no Play Store). iOS keeps the PWA (Add to Home Screen), and the
// PWA remains the no-download fallback everywhere else. Inside the Android app
// itself all install prompts collapse into an "installed" state.
const { canInstall, installed, isStandalone, isIOS, promptInstall } = useInstallPrompt()

const isNative = isNativeApp()
const appUrl = window.location.origin

// VERSIONED download URL, derived from version.json. Every release gets a
// brand-new URL, so no cache between the phone and the server (Cloudflare
// edge, browser HTTP cache — .apk is on Cloudflare's default cache list) can
// ever replay an old build. Bare plaza-pro.apk stays as the fallback while
// version.json hasn't loaded. Both files published by scripts/build-android.sh.
const apkUrl = ref('/downloads/plaza-pro.apk')
const apkVersion = ref(null)
const latestCode = ref(null)
onMounted(async () => {
  try {
    const res = await fetch('/downloads/version.json', { cache: 'no-store' })
    if (res.ok) {
      const meta = await res.json()
      const v = meta.versionName || null
      apkVersion.value = v
      if (v) apkUrl.value = `/downloads/plaza-pro-v${v}.apk`
      if (Number.isInteger(meta.versionCode)) latestCode.value = meta.versionCode
    }
  } catch {
    apkVersion.value = null
  }
})

// Inside the APK: compare this build against the published one so the page can
// offer the update right here (the UpdateBanner's button lands on this page).
const currentBuild = isNative ? installedAppVersion() : null
const updateReady = computed(
  () => isNative && latestCode.value !== null && latestCode.value > currentBuild.versionCode
)
const upToDate = computed(
  () => isNative && latestCode.value !== null && latestCode.value <= currentBuild.versionCode
)

// The WebView has no DownloadListener, so an in-page APK link would be
// swallowed. Navigating to the http:// flavor of the URL instead makes the
// scheme differ from the shell's https app URL, so Capacitor's launchIntent
// hands it to the system browser (ACTION_VIEW) — which follows the server's
// 301 back to https (Cloudflare and LAN nginx both redirect) and downloads
// the APK. Works on every shell already in the field, no bridge method needed.
function downloadUpdate() {
  window.location.href = `http://${window.location.host}${apkUrl.value}`
}
</script>

<template>
  <div>
    <PageHeader
:title="$t('nav.mobileApp')"
      :subtitle="$t('install.subtitle')"
    />

    <div class="mx-auto max-w-2xl space-y-5">
      <!-- Brand hero -->
      <SectionCard>
        <div class="flex items-center gap-4">
          <img
            src="/icons/icon-192.png"
:alt="$t('install.appIconAlt')"
            class="h-16 w-16 rounded-2xl shadow-card"
          />
          <div>
            <div class="text-base font-semibold text-ink">PLAZA PRO</div>
            <div class="text-sm text-mute">
              {{ $t('install.heroBody') }}
            </div>
          </div>
        </div>
      </SectionCard>

      <!-- Inside the Android app: a newer APK is published — update from here -->
      <SectionCard v-if="isNative && updateReady" :title="$t('install.updateAvailable')" icon="pi pi-arrow-circle-up">
        <p class="mb-1 text-sm text-ink">
          {{ $t('install.versionOut', { version: apkVersion }) }}<template v-if="currentBuild?.versionName">
            — {{ $t('install.youHave', { version: currentBuild.versionName }) }}</template
          >.
        </p>
        <p class="mb-4 text-sm text-mute">
          {{ $t('install.updateHint') }}
        </p>
        <BaseButton icon="pi pi-download" :label="$t('install.downloadUpdate')" @click="downloadUpdate" />
        <ol class="mt-4 list-inside list-decimal space-y-2 text-sm text-ink">
          <li>{{ $t('install.stepOpenDownload') }}</li>
          <li>{{ $t('install.stepAllowSource') }}</li>
          <li>{{ $t('install.stepUpdateReopen') }}</li>
        </ol>
      </SectionCard>

      <!-- Inside the Android app, already current: nothing to install -->
      <SectionCard v-else-if="isNative" :title="$t('install.installed')" icon="pi pi-check-circle">
        <p class="text-sm text-ink">
          {{ $t('install.usingNativeApp') }}<template v-if="currentBuild?.versionName">
            ({{ $t('install.version') }} {{ currentBuild.versionName }})</template
          ><template v-if="upToDate"> — {{ $t('install.onLatest') }}</template
          >. {{ $t('install.autoUpdates') }}
        </p>
      </SectionCard>

      <template v-else>
        <!-- Android: the dedicated app, downloaded directly from this server -->
        <SectionCard v-if="!isIOS" :title="$t('install.androidApp')" icon="pi pi-android">
          <p class="mb-1 text-sm text-mute">
            {{ $t('install.androidBody') }}
          </p>
          <p v-if="apkVersion" class="mb-4 text-xs text-mute">{{ $t('install.version') }} {{ apkVersion }}</p>
          <p v-else class="mb-4"></p>
          <BaseButton as="a" :href="apkUrl" icon="pi pi-download" :label="$t('install.downloadAndroid')" />
          <ol class="mt-4 list-inside list-decimal space-y-2 text-sm text-ink">
            <li>{{ $t('install.stepOpenDownload') }}</li>
            <li>{{ $t('install.stepAllowSource') }}</li>
            <li>{{ $t('install.stepInstallIcon') }}</li>
          </ol>
        </SectionCard>

        <!-- Already installed as PWA -->
        <SectionCard v-if="isStandalone || installed" :title="$t('install.installed')" icon="pi pi-check-circle">
          <p class="text-sm text-ink">
            {{ $t('install.usingPwa') }}
          </p>
        </SectionCard>

        <!-- Android / desktop: native one-tap install -->
        <SectionCard
          v-else-if="canInstall"
          :title="isIOS ? $t('install.installNow') : $t('install.preferNoDownload')"
          icon="pi pi-download"
        >
          <p class="mb-4 text-sm text-mute">
            {{ $t('install.oneTap') }}
          </p>
          <BaseButton :label="$t('install.installButton')" icon="pi pi-mobile" @click="promptInstall" />
        </SectionCard>

        <!-- iOS: Safari's manual flow -->
        <SectionCard v-else-if="isIOS" :title="$t('install.iosTitle')" icon="pi pi-apple">
          <ol class="list-inside list-decimal space-y-2 text-sm text-ink">
            <li>{{ $t('install.iosStep1', { url: appUrl }) }}</li>
            <li>{{ $t('install.iosStep2') }}</li>
            <li>{{ $t('install.iosStep3') }}</li>
            <li>{{ $t('install.iosStep4') }}</li>
          </ol>
        </SectionCard>

        <!-- Fallback: browser without the install event (e.g. Firefox desktop) -->
        <SectionCard v-else :title="$t('install.orWebApp')" icon="pi pi-mobile">
          <div class="space-y-4 text-sm text-ink">
            <div>
              <div class="mb-1 font-medium">Android (Chrome)</div>
              <p class="text-mute">
                {{ $t('install.chromeManual', { url: appUrl }) }}
              </p>
            </div>
            <div>
              <div class="mb-1 font-medium">iPhone / iPad (Safari)</div>
              <p class="text-mute">
                {{ $t('install.safariManual', { url: appUrl }) }}
              </p>
            </div>
          </div>
        </SectionCard>
      </template>

      <!-- What you get -->
      <SectionCard :title="$t('install.whatYouGet')" icon="pi pi-sparkles">
        <ul class="space-y-2 text-sm text-mute">
          <li class="flex items-start gap-2">
            <i class="pi pi-check mt-0.5 text-primary" aria-hidden="true" />
            <span>{{ $t('install.featureEverything') }}</span>
          </li>
          <li class="flex items-start gap-2">
            <i class="pi pi-check mt-0.5 text-primary" aria-hidden="true" />
            <span>{{ $t('install.featureLive') }}</span>
          </li>
          <li class="flex items-start gap-2">
            <i class="pi pi-check mt-0.5 text-primary" aria-hidden="true" />
            <span>{{ $t('install.featureFullscreen') }}</span>
          </li>
          <li class="flex items-start gap-2">
            <i class="pi pi-check mt-0.5 text-primary" aria-hidden="true" />
            <span>{{ $t('install.featureAnywhere') }}</span>
          </li>
        </ul>
      </SectionCard>
    </div>
  </div>
</template>
