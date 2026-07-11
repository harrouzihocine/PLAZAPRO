<script setup>
import { computed, onMounted, ref } from 'vue'
import Popover from 'primevue/popover'
import { copyToClipboard } from '@/composables/useClipboard'
import { buildOfficeInviteText, officeProfile } from '@/features/pipeline/officeInvite'
import { t } from '@/i18n'

// A one-tap "invite the client to the office" action, offered on planned
// office visits (dashboard work items + the client-file timeline). It composes
// the invitation from the office profile and the visit's date/time, then hands
// it off over the client's own channels — WhatsApp, SMS, or copy — the same
// share pattern used everywhere else for client contact. No backend send.
const props = defineProps({
  clientName: { type: String, default: '' },
  phone: { type: String, default: '' },
  // The scheduled office-visit moment (ISO string / Date); shown in the message.
  scheduledAt: { type: [String, Number, Date], default: null },
  // 'sm' matches the compact icons in dashboard rows; 'md' the timeline block.
  size: { type: String, default: 'md' },
})

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

const pop = ref(null)
function toggle(e) {
  if (!hasPhone.value) return
  pop.value?.toggle(e)
}
async function copy() {
  await copyToClipboard(message.value, t('officeInvite.copied'))
  pop.value?.hide()
}
</script>

<template>
  <span>
    <button
      type="button"
      class="inline-flex shrink-0 items-center justify-center rounded-full text-primary-600 transition-colors hover:bg-primary-50 disabled:opacity-40 dark:text-primary-400 dark:hover:bg-primary-500/10"
      :class="size === 'sm' ? 'h-6 w-6' : 'h-8 w-8'"
      :disabled="!hasPhone"
      :title="hasPhone ? $t('officeInvite.action') : $t('officeInvite.noPhone')"
      :aria-label="clientName ? $t('officeInvite.ariaSend', { name: clientName }) : $t('officeInvite.action')"
      @click.stop.prevent="toggle"
    >
      <i class="pi pi-send" :class="size === 'sm' ? 'text-sm' : ''" aria-hidden="true" />
    </button>

    <Popover ref="pop" class="w-56 max-w-[92vw]">
      <div class="space-y-1">
        <p class="px-2 pb-1 text-xs font-semibold uppercase tracking-wide text-mute">
          {{ $t('officeInvite.action') }}
        </p>
        <a
          :href="whatsappUrl"
          target="_blank"
          rel="noopener"
          class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm text-ink transition-colors hover:bg-surface-50 dark:hover:bg-surface-800"
          @click="pop?.hide()"
        >
          <i class="pi pi-whatsapp text-green-600 dark:text-green-400" aria-hidden="true" />
          {{ $t('officeInvite.whatsapp') }}
        </a>
        <a
          :href="smsUrl"
          class="flex items-center gap-2.5 rounded-lg px-2 py-2 text-sm text-ink transition-colors hover:bg-surface-50 dark:hover:bg-surface-800"
          @click="pop?.hide()"
        >
          <i class="pi pi-comment text-primary-600 dark:text-primary-400" aria-hidden="true" />
          {{ $t('officeInvite.sms') }}
        </a>
        <button
          type="button"
          class="flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-sm text-ink transition-colors hover:bg-surface-50 dark:hover:bg-surface-800"
          @click="copy"
        >
          <i class="pi pi-copy text-mute" aria-hidden="true" />
          {{ $t('officeInvite.copy') }}
        </button>
      </div>
    </Popover>
  </span>
</template>
