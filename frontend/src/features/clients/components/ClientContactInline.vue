<script setup>
import SendToPhoneButton from '@/features/clients/components/SendToPhoneButton.vue'
import { formatPhone } from '@/data/countryCodes'

// Inline "phone number + call + WhatsApp" affordance, reused wherever a client's
// number should be reachable in one tap (client project header, Team/My Logs
// rows). The call button is the web click-to-call handoff — it hides on the APK
// and without calls.log (see SendToPhoneButton); WhatsApp is a plain wa.me deep
// link. Renders nothing when there is no phone (the caller's view-details gate).
defineProps({
  clientId: { type: [String, Number], required: true },
  phone: { type: String, default: '' },
  // 'sm' matches list rows / compact headers; 'md' the roomier profile line.
  size: { type: String, default: 'sm' },
})

// Digits only, E.164 — WhatsApp wants the bare international number.
const whatsappLink = (phone) => `https://wa.me/${(phone ?? '').replace(/\D/g, '')}`
</script>

<template>
  <span v-if="phone" class="inline-flex items-center gap-1.5">
    <span class="num ltr-data">{{ formatPhone(phone) }}</span>
    <SendToPhoneButton :client-id="clientId" :size="size" />
    <a
      :href="whatsappLink(phone)"
      target="_blank"
      rel="noopener"
      class="inline-flex shrink-0 items-center justify-center rounded-full text-emerald-600 transition-colors hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-500/10"
      :class="size === 'sm' ? 'h-5 w-5' : 'h-6 w-6'"
      :aria-label="$t('clients.whatsappTitle')"
      :title="$t('clients.whatsappTitle')"
      @click.stop
    >
      <i class="pi pi-whatsapp" :class="size === 'sm' ? 'text-sm' : ''" aria-hidden="true" />
    </a>
  </span>
</template>
