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
      title="Mobile App"
      subtitle="Install PLAZA PRO on your phone — same account, same features, opens fullscreen like a native app."
    />

    <div class="mx-auto max-w-2xl space-y-5">
      <!-- Brand hero -->
      <SectionCard>
        <div class="flex items-center gap-4">
          <img
            src="/icons/icon-192.png"
            alt="PLAZA PRO app icon"
            class="h-16 w-16 rounded-2xl shadow-card"
          />
          <div>
            <div class="text-base font-semibold text-ink">PLAZA PRO</div>
            <div class="text-sm text-mute">
              Works on iOS and Android. Updates automatically — you will always have the
              latest version, no re-download needed.
            </div>
          </div>
        </div>
      </SectionCard>

      <!-- Inside the Android app: a newer APK is published — update from here -->
      <SectionCard v-if="isNative && updateReady" title="Update available" icon="pi pi-arrow-circle-up">
        <p class="mb-1 text-sm text-ink">
          Version {{ apkVersion }} of the app is out<template v-if="currentBuild?.versionName">
            — you have {{ currentBuild.versionName }}</template
          >.
        </p>
        <p class="mb-4 text-sm text-mute">
          The download opens in your browser. Your account and data stay in place —
          installing on top just updates the app.
        </p>
        <BaseButton icon="pi pi-download" label="Download update (.apk)" @click="downloadUpdate" />
        <ol class="mt-4 list-inside list-decimal space-y-2 text-sm text-ink">
          <li>Open the finished download (tap it in the browser's download bar).</li>
          <li>
            If asked about unknown apps: tap <span class="font-medium">Settings</span> →
            <span class="font-medium">"Allow from this source"</span>, then go back.
          </li>
          <li>Tap <span class="font-medium">Update</span>, then reopen PLAZA PRO.</li>
        </ol>
      </SectionCard>

      <!-- Inside the Android app, already current: nothing to install -->
      <SectionCard v-else-if="isNative" title="Installed" icon="pi pi-check-circle">
        <p class="text-sm text-ink">
          You are using the PLAZA PRO Android app<template v-if="currentBuild?.versionName">
            (version {{ currentBuild.versionName }})</template
          ><template v-if="upToDate"> — you are on the latest version</template
          >. New features arrive automatically with every update of the web app.
        </p>
      </SectionCard>

      <template v-else>
        <!-- Android: the dedicated app, downloaded directly from this server -->
        <SectionCard v-if="!isIOS" title="Android app" icon="pi pi-android">
          <p class="mb-1 text-sm text-mute">
            The dedicated PLAZA PRO app for Android — download it straight from here, no
            Play Store needed.
          </p>
          <p v-if="apkVersion" class="mb-4 text-xs text-mute">Version {{ apkVersion }}</p>
          <p v-else class="mb-4"></p>
          <BaseButton as="a" :href="apkUrl" icon="pi pi-download" label="Download for Android (.apk)" />
          <ol class="mt-4 list-inside list-decimal space-y-2 text-sm text-ink">
            <li>Open the finished download (tap it in Chrome's download bar).</li>
            <li>
              If Chrome warns about unknown apps: tap
              <span class="font-medium">Settings</span> →
              <span class="font-medium">"Allow from this source"</span>, then go back.
            </li>
            <li>Tap <span class="font-medium">Install</span> — the PLAZA PRO icon appears on your home screen.</li>
          </ol>
        </SectionCard>

        <!-- Already installed as PWA -->
        <SectionCard v-if="isStandalone || installed" title="Installed" icon="pi pi-check-circle">
          <p class="text-sm text-ink">
            You are using the installed app — nothing more to do. The icon is on your home
            screen and stays signed in like the website.
          </p>
        </SectionCard>

        <!-- Android / desktop: native one-tap install -->
        <SectionCard
          v-else-if="canInstall"
          :title="isIOS ? 'Install now' : 'Prefer no download? Install the web app'"
          icon="pi pi-download"
        >
          <p class="mb-4 text-sm text-mute">
            One tap adds PLAZA PRO to your home screen and opens it fullscreen.
          </p>
          <BaseButton label="Install PLAZA PRO" icon="pi pi-mobile" @click="promptInstall" />
        </SectionCard>

        <!-- iOS: Safari's manual flow -->
        <SectionCard v-else-if="isIOS" title="Install on iPhone / iPad" icon="pi pi-apple">
          <ol class="list-inside list-decimal space-y-2 text-sm text-ink">
            <li>Open <span class="font-medium">{{ appUrl }}</span> in <span class="font-medium">Safari</span> (not Chrome).</li>
            <li>Tap the <span class="font-medium">Share</span> button <i class="pi pi-upload text-mute" aria-hidden="true" /> in the toolbar.</li>
            <li>Scroll and tap <span class="font-medium">"Add to Home Screen"</span>.</li>
            <li>Tap <span class="font-medium">Add</span> — the PLAZA PRO icon appears on your home screen.</li>
          </ol>
        </SectionCard>

        <!-- Fallback: browser without the install event (e.g. Firefox desktop) -->
        <SectionCard v-else title="Or install the web app" icon="pi pi-mobile">
          <div class="space-y-4 text-sm text-ink">
            <div>
              <div class="mb-1 font-medium">Android (Chrome)</div>
              <p class="text-mute">
                Open <span class="font-medium text-ink">{{ appUrl }}</span> in Chrome → menu
                <i class="pi pi-ellipsis-v text-xs" aria-hidden="true" /> →
                <span class="font-medium text-ink">"Add to Home screen"</span> → Install.
              </p>
            </div>
            <div>
              <div class="mb-1 font-medium">iPhone / iPad (Safari)</div>
              <p class="text-mute">
                Open <span class="font-medium text-ink">{{ appUrl }}</span> in Safari → Share →
                <span class="font-medium text-ink">"Add to Home Screen"</span> → Add.
              </p>
            </div>
          </div>
        </SectionCard>
      </template>

      <!-- What you get -->
      <SectionCard title="What you get" icon="pi pi-sparkles">
        <ul class="space-y-2 text-sm text-mute">
          <li class="flex items-start gap-2">
            <i class="pi pi-check mt-0.5 text-primary" aria-hidden="true" />
            <span>Everything from the web app: clients, inventory, deals, payments, chat.</span>
          </li>
          <li class="flex items-start gap-2">
            <i class="pi pi-check mt-0.5 text-primary" aria-hidden="true" />
            <span>Live notifications and chat while the app is open.</span>
          </li>
          <li class="flex items-start gap-2">
            <i class="pi pi-check mt-0.5 text-primary" aria-hidden="true" />
            <span>Fullscreen, its own icon and switcher entry — no browser bar.</span>
          </li>
          <li class="flex items-start gap-2">
            <i class="pi pi-check mt-0.5 text-primary" aria-hidden="true" />
            <span>Works in the office and outside — one address, secure HTTPS.</span>
          </li>
        </ul>
      </SectionCard>
    </div>
  </div>
</template>
