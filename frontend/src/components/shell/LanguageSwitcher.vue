<script setup>
import { ref } from 'vue'
import Button from 'primevue/button'
import Popover from 'primevue/popover'
import { useI18n } from 'vue-i18n'
import { SUPPORTED_LOCALES, LOCALE_NAMES, setLocale } from '@/i18n'
import { useAuthStore } from '@/features/settings/store'

// Globe menu offering English / Français / العربية. Language names render in
// their own script on purpose — a picker must be readable in the language it
// offers. Signed-in switches persist on the profile; the login screen just
// switches locally (the choice is pushed to the profile right after login).
const { locale } = useI18n()
const panel = ref(null)
const auth = useAuthStore()

function choose(l) {
  panel.value?.hide()
  if (l === locale.value) return
  setLocale(l)
  if (auth.isAuthenticated) auth.saveLocale(l)
}
</script>

<template>
  <Button
    icon="pi pi-globe"
    text
    rounded
    severity="secondary"
    :aria-label="$t('shell.changeLanguage')"
    @click="panel.toggle($event)"
  />
  <Popover ref="panel" class="w-44">
    <div class="space-y-1 px-1 py-1">
      <button
        v-for="l in SUPPORTED_LOCALES"
        :key="l"
        type="button"
        class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm transition-colors hover:bg-surface-100 dark:hover:bg-surface-800"
        :class="l === locale ? 'font-semibold text-ink' : 'text-mute'"
        :lang="l"
        :dir="l === 'ar' ? 'rtl' : 'ltr'"
        @click="choose(l)"
      >
        <span class="flex-1 text-start">{{ LOCALE_NAMES[l] }}</span>
        <i v-if="l === locale" class="pi pi-check text-xs text-primary-600" aria-hidden="true" />
      </button>
    </div>
  </Popover>
</template>
