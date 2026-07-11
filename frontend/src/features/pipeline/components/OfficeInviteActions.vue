<script setup>
import { computed, onMounted, ref } from 'vue'
import { copyToClipboard } from '@/composables/useClipboard'
import { buildOfficeInviteText, officeProfile } from '@/features/pipeline/officeInvite'
import { t } from '@/i18n'

// The shared body of the office-visit invitation: composes the message from the
// office profile + the (optional) visit date, then offers the three hand-off
// channels — WhatsApp, SMS, copy. Rendered inside the icon-button popover
// (OfficeVisitInviteButton) and the dashboard quick-action modal alike. Emits
// `done` when the agent picks a channel so the host can close.
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
const encoded = computed(() => encodeURIComponent(message.value))
const whatsappUrl = computed(() => `https://wa.me/${digits.value}?text=${encoded.value}`)
// Android reads ?body= on the sms: scheme; keeps the raw number as typed.
const smsUrl = computed(() => `sms:${props.phone}?body=${encoded.value}`)

async function copy() {
  await copyToClipboard(message.value, t('officeInvite.copied'))
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
    <template v-if="hasPhone">
      <a :href="whatsappUrl" target="_blank" rel="noopener" :class="rowClass" @click="$emit('done')">
        <i class="pi pi-whatsapp text-green-600 dark:text-green-400" aria-hidden="true" />
        {{ $t('officeInvite.whatsapp') }}
      </a>
      <a :href="smsUrl" :class="rowClass" @click="$emit('done')">
        <i class="pi pi-comment text-primary-600 dark:text-primary-400" aria-hidden="true" />
        {{ $t('officeInvite.sms') }}
      </a>
    </template>
    <p v-else class="flex items-center gap-2 px-2 py-1.5 text-xs text-mute">
      <i class="pi pi-exclamation-triangle" aria-hidden="true" />
      {{ $t('officeInvite.noPhone') }}
    </p>
    <button type="button" :class="rowClass" @click="copy">
      <i class="pi pi-copy text-mute" aria-hidden="true" />
      {{ $t('officeInvite.copy') }}
    </button>
  </div>
</template>
