<script setup>
import { computed, onMounted, ref } from 'vue'
import { copyToClipboard } from '@/composables/useClipboard'
import { buildOfficeInviteText, officeProfile } from '@/features/pipeline/officeInvite'
import { t } from '@/i18n'

// The shared body of the office-visit invitation: composes the message from the
// office profile + the (optional) visit date and sends it over WhatsApp — the
// one channel used for this invitation. A separate "copy address" button lets
// the agent grab the office address (+ Maps link) to paste anywhere. Rendered
// inside the icon-button popover (OfficeVisitInviteButton) and the dashboard
// quick-action modal alike. Emits `done` when the agent acts so the host closes.
const props = defineProps({
  clientName: { type: String, default: '' },
  phone: { type: String, default: '' },
  scheduledAt: { type: [String, Number, Date], default: null },
})
const emit = defineEmits(['done'])

const profile = ref({ company: '', address: '', maps: '', phone: '' })
onMounted(async () => {
  profile.value = await officeProfile()
})

// Match the existing wa.me convention: strip to bare digits for the number.
const digits = computed(() => (props.phone || '').replace(/\D/g, ''))
const hasPhone = computed(() => digits.value.length > 0)

const message = computed(() =>
  buildOfficeInviteText({ name: props.clientName, dateTime: props.scheduledAt, profile: profile.value }),
)
const whatsappUrl = computed(() => `https://wa.me/${digits.value}?text=${encodeURIComponent(message.value)}`)

// The office location to paste elsewhere: address then the Maps link if set.
const addressText = computed(() => [profile.value.address, profile.value.maps].filter(Boolean).join('\n'))
const hasAddress = computed(() => addressText.value.length > 0)

async function copyAddress() {
  await copyToClipboard(addressText.value, t('officeInvite.addressCopied'))
  emit('done')
}

const rowClass =
  'flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-sm text-ink transition-colors hover:bg-surface-50 dark:hover:bg-surface-800'
</script>

<template>
  <div class="space-y-1">
    <p class="px-2 pb-1 text-xs font-semibold uppercase tracking-wide text-mute">
      {{ $t('officeInvite.action') }}
    </p>
    <a v-if="hasPhone" :href="whatsappUrl" target="_blank" rel="noopener" :class="rowClass" @click="$emit('done')">
      <i class="pi pi-whatsapp text-green-600 dark:text-green-400" aria-hidden="true" />
      {{ $t('officeInvite.whatsapp') }}
    </a>
    <p v-else class="flex items-center gap-2 px-2 py-1.5 text-xs text-mute">
      <i class="pi pi-exclamation-triangle" aria-hidden="true" />
      {{ $t('officeInvite.noPhone') }}
    </p>
    <button v-if="hasAddress" type="button" :class="rowClass" @click="copyAddress">
      <i class="pi pi-map-marker text-mute" aria-hidden="true" />
      {{ $t('officeInvite.copyAddress') }}
    </button>
  </div>
</template>
