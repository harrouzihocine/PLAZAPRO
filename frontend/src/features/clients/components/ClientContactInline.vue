<script setup>
import { computed } from 'vue'
import SendToPhoneButton from '@/features/clients/components/SendToPhoneButton.vue'
import { formatPhone } from '@/data/countryCodes'
import { isNativeApp } from '@/utils/nativeApp'
import { t } from '@/i18n'

// Inline "phone number + call + WhatsApp" affordance, reused wherever a client's
// number should be reachable in one tap (client project header, Team/My Logs
// rows). Renders nothing when there is no phone (the caller's view-details gate).
//
// The call button is platform-aware: on the phone APK it is a direct tel:
// dialer link (Capacitor hands tel: to the OS dialer — the on-device
// equivalent of, and preferred over, the click-to-call handoff); on the web it
// is SendToPhoneButton, which pushes the number to the agent's own phone and
// hides itself without calls.log. WhatsApp is a plain wa.me deep link on both.
const props = defineProps({
  clientId: { type: [String, Number], required: true },
  phone: { type: String, default: '' },
  // Optional — powers descriptive "Call {name}" / "WhatsApp {name}" labels.
  clientName: { type: String, default: '' },
  // 'sm' matches list rows / compact headers; 'md' the roomier profile line.
  size: { type: String, default: 'sm' },
})

// Stable for the session — the shell never turns into a browser mid-run.
const native = isNativeApp()

// Digits only (keep a leading +) — both tel: and wa.me want the bare number.
const dialLink = computed(() => `tel:${(props.phone ?? '').replace(/[^\d+]/g, '')}`)
const whatsappLink = computed(() => `https://wa.me/${(props.phone ?? '').replace(/\D/g, '')}`)

const callLabel = computed(() =>
  props.clientName ? t('clients.callAria', { name: props.clientName }) : t('clients.callFromPhone'),
)
const whatsappLabel = computed(() =>
  props.clientName ? t('clients.whatsappAria', { name: props.clientName }) : t('clients.whatsappTitle'),
)
</script>

<template>
  <span v-if="phone" class="inline-flex items-center gap-1.5">
    <span class="num ltr-data">{{ formatPhone(phone) }}</span>
    <!-- Call: direct dialer on the APK, click-to-call handoff on the web. -->
    <a
      v-if="native"
      :href="dialLink"
      class="inline-flex shrink-0 items-center justify-center rounded-full text-primary-600 transition-colors hover:bg-primary-50 dark:text-primary-400 dark:hover:bg-primary-500/10"
      :class="size === 'sm' ? 'h-5 w-5' : 'h-6 w-6'"
      :aria-label="callLabel"
      :title="callLabel"
      @click.stop
    >
      <i class="pi pi-phone" :class="size === 'sm' ? 'text-sm' : ''" aria-hidden="true" />
    </a>
    <SendToPhoneButton v-else :client-id="clientId" :size="size" />
    <a
      :href="whatsappLink"
      target="_blank"
      rel="noopener"
      class="inline-flex shrink-0 items-center justify-center rounded-full text-emerald-600 transition-colors hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-500/10"
      :class="size === 'sm' ? 'h-5 w-5' : 'h-6 w-6'"
      :aria-label="whatsappLabel"
      :title="whatsappLabel"
      @click.stop
    >
      <i class="pi pi-whatsapp" :class="size === 'sm' ? 'text-sm' : ''" aria-hidden="true" />
    </a>
  </span>
</template>
