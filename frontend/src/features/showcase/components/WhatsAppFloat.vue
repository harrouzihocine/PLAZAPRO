<script setup>
import { computed } from 'vue'
import { useShowcaseStore } from '../store'
import { track } from '../composables/useTracker'
import { t } from '@/i18n'

// The market's #1 conversion channel: a persistent WhatsApp click-to-chat
// button. Same wa.me convention as the CRM (digits only + encoded text).

const showcase = useShowcaseStore()

const href = computed(() => {
  const digits = (showcase.whatsappNumber ?? '').replace(/\D/g, '')
  if (!digits) return null
  return `https://wa.me/${digits}?text=${encodeURIComponent(t('showcase.whatsapp.prefill'))}`
})
</script>

<template>
  <a
    v-if="href"
    :href="href"
    target="_blank"
    rel="noopener"
    class="fixed bottom-5 end-5 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-[#25d366] text-white shadow-pop transition-transform hover:scale-105"
    :aria-label="$t('showcase.whatsapp.chat')"
    @click="track('whatsapp_click')"
  >
    <i class="pi pi-whatsapp text-2xl" aria-hidden="true" />
  </a>
</template>
