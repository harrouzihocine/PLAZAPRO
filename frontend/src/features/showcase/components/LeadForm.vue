<script setup>
import { computed, ref } from 'vue'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import { useI18n } from 'vue-i18n'
import { todayInput } from '@/utils/format'
import { useShowcaseStore } from '../store'

// The lead form — the whole point of the site. Server-side anti-spam rides
// along invisibly: the `website` honeypot below (off-screen, bots fill it),
// and the form_token min-fill-time check (the store attaches the token).

const props = defineProps({
  type: { type: String, default: 'interest' }, // interest | visit_request | callback
  project: { type: Object, default: null },
  unit: { type: Object, default: null },
})

const emit = defineEmits(['submitted'])

const showcase = useShowcaseStore()
const { t } = useI18n()

const name = ref('')
const phone = ref('')
const message = ref('')
const preferredDate = ref('')
const preferredTime = ref(null)
const honeypot = ref('')

const sending = ref(false)
const sent = ref(false)
const error = ref(null)

const isVisit = computed(() => props.type === 'visit_request')
const minDate = todayInput()

// Office-hours slots (the CRM's :00/:30 convention).
const timeOptions = []
for (let h = 9; h <= 16; h++) {
  for (const m of ['00', '30']) timeOptions.push(`${String(h).padStart(2, '0')}:${m}`)
}

async function submit() {
  error.value = null
  sending.value = true
  try {
    await showcase.submitLead({
      name: name.value.trim(),
      phone: phone.value.trim(),
      message: message.value.trim() || null,
      type: props.type,
      location_id: props.project?.id ?? null,
      unit_id: props.unit?.id ?? null,
      preferred_date: isVisit.value ? preferredDate.value || null : null,
      preferred_time: isVisit.value ? preferredTime.value : null,
      website: honeypot.value, // honeypot — empty for humans
    })
    sent.value = true
    emit('submitted')
  } catch (e) {
    error.value =
      e.response?.status === 429
        ? t('showcase.lead.tooMany')
        : (Object.values(e.response?.data?.errors ?? {})[0]?.[0] ?? t('showcase.lead.failed'))
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <!-- Success state -->
  <div v-if="sent" class="flex flex-col items-center py-8 text-center">
    <span class="flex h-16 w-16 items-center justify-center rounded-full bg-success/10 text-success">
      <i class="pi pi-check text-3xl" aria-hidden="true" />
    </span>
    <h3 class="mt-4 text-lg font-semibold text-ink">{{ $t('showcase.lead.sentTitle') }}</h3>
    <p class="mt-1.5 max-w-sm text-sm text-mute">{{ $t('showcase.lead.sentBody') }}</p>
  </div>

  <form v-else class="relative flex flex-col gap-4" @submit.prevent="submit">
    <p v-if="unit || project" class="flex items-center gap-2 rounded-lg bg-primary-500/10 px-3 py-2 text-sm text-primary-700 dark:text-primary-300">
      <i class="pi pi-tag" aria-hidden="true" />
      <span class="truncate">{{ unit ? `${unit.rooms || ''} — ${project?.name}` : project?.name }}</span>
    </p>

    <div class="flex flex-col gap-1.5">
      <label :for="`lead-name-${type}`" class="text-sm font-medium text-ink"
        >{{ $t('showcase.lead.name') }}<span class="text-danger" aria-hidden="true"> *</span></label
      >
      <InputText :id="`lead-name-${type}`" v-model="name" required :maxlength="120" fluid autocomplete="name" />
    </div>

    <div class="flex flex-col gap-1.5">
      <label :for="`lead-phone-${type}`" class="text-sm font-medium text-ink"
        >{{ $t('showcase.lead.phone') }}<span class="text-danger" aria-hidden="true"> *</span></label
      >
      <InputText
        :id="`lead-phone-${type}`"
        v-model="phone"
        type="tel"
        required
        :maxlength="32"
        class="ltr-data"
        fluid
        autocomplete="tel"
        placeholder="0550 00 00 00"
      />
    </div>

    <div v-if="isVisit" class="grid grid-cols-2 gap-3">
      <div class="flex flex-col gap-1.5">
        <label :for="`lead-date-${type}`" class="text-sm font-medium text-ink">{{ $t('showcase.lead.date') }}</label>
        <input
          :id="`lead-date-${type}`"
          v-model="preferredDate"
          type="date"
          :min="minDate"
          class="rounded-md border border-line bg-card px-3 py-2 text-sm text-ink outline-none focus:border-primary-500"
        />
      </div>
      <div class="flex flex-col gap-1.5">
        <label :for="`lead-time-${type}`" class="text-sm font-medium text-ink">{{ $t('showcase.lead.time') }}</label>
        <Select
          :input-id="`lead-time-${type}`"
          v-model="preferredTime"
          :options="timeOptions"
          show-clear
          :placeholder="'—'"
          fluid
        />
      </div>
    </div>

    <div class="flex flex-col gap-1.5">
      <label :for="`lead-message-${type}`" class="text-sm font-medium text-ink">{{ $t('showcase.lead.message') }}</label>
      <Textarea :id="`lead-message-${type}`" v-model="message" rows="3" :maxlength="2000" auto-resize fluid />
    </div>

    <!-- Honeypot: invisible to humans, irresistible to bots. -->
    <div class="pointer-events-none absolute -start-[9999px] top-auto h-px w-px overflow-hidden" aria-hidden="true">
      <label for="lead-website">Website</label>
      <input id="lead-website" v-model="honeypot" type="text" tabindex="-1" autocomplete="off" />
    </div>

    <p v-if="error" class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">{{ error }}</p>

    <Button
      type="submit"
      :label="sending ? $t('showcase.lead.sending') : $t(`showcase.lead.submit_${type}`)"
      :loading="sending"
      icon="pi pi-send"
      icon-pos="right"
      class="mt-1"
    />

    <p class="text-center text-xs text-mute">{{ $t('showcase.lead.privacy') }}</p>
  </form>
</template>
