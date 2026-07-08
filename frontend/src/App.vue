<script setup>
import { onMounted, watch } from 'vue'
import { RouterView } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { usePrimeVue } from 'primevue/config'
import { useTheme } from '@/composables/useTheme'
import { PRIMEVUE_LOCALES } from '@/i18n/primevue'

onMounted(() => {
  useTheme().init()
})

// PrimeVue's own strings (paginator, filter menus, aria labels) follow the app
// language. <html lang dir> is stamped by setLocale() in @/i18n.
const { locale } = useI18n()
const primevue = usePrimeVue()
watch(locale, (l) => {
  primevue.config.locale = PRIMEVUE_LOCALES[l]
})
</script>

<template>
  <RouterView />
</template>
