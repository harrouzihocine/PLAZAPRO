<script setup>
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import MultiSelect from 'primevue/multiselect'
import Textarea from 'primevue/textarea'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { currentLocale } from '@/i18n'
import { MIL_LABEL, milToDzd } from '@/features/payments/money'
import { useShowcaseStore } from '../store'

// The "tell us what you're looking for" wizard — for the visitor who browsed
// and found nothing. Three light steps (what → where & budget → contact),
// everything skippable except name + phone, big tappable chips on phones.
// Same anti-spam ride-alongs as LeadForm: honeypot + form_token.

const emit = defineEmits(['submitted'])

const showcase = useShowcaseStore()
const { t } = useI18n()

onMounted(() => showcase.loadDesireOptions())

const options = computed(() => showcase.desireOptions)

// ── Wizard state ─────────────────────────────────────────────────────
const step = ref(1)
const STEPS = 3

const typeIds = ref([])
const roomNumberIds = ref([])
const wilayaIds = ref([])
const communeIds = ref([])
const budgetMinMil = ref(null)
const budgetMaxMil = ref(null)
const name = ref('')
const phone = ref('')
const message = ref('')
const honeypot = ref('')

const sending = ref(false)
const sent = ref(false)
const error = ref(null)

// Dynamic-list options carry their full {en,fr,ar} labels — pick per render
// so a language switch re-labels the chips without a refetch.
function itemLabel(item) {
  return item.labels?.[currentLocale()] || item.label
}

function toggle(list, id) {
  const i = list.value.indexOf(id)
  if (i === -1) list.value.push(id)
  else list.value.splice(i, 1)
}

const wilayaOptions = computed(() => options.value?.wilayas ?? [])

// Communes narrow to the chosen wilayas; dropping a wilaya drops its picks.
const communeOptions = computed(() => {
  if (!wilayaIds.value.length) return []
  return (options.value?.communes ?? []).filter((c) => wilayaIds.value.includes(c.wilaya_id))
})

function onWilayasChange() {
  const allowed = new Set(communeOptions.value.map((c) => c.id))
  communeIds.value = communeIds.value.filter((id) => allowed.has(id))
}

// A friendly recap on the contact step — the visitor sees what we understood.
const recap = computed(() => {
  const parts = []
  const opts = options.value
  if (!opts) return parts
  const label = (list, ids) => list.filter((i) => ids.includes(i.id)).map(itemLabel)
  parts.push(...label(opts.types ?? [], typeIds.value))
  parts.push(...label(opts.room_numbers ?? [], roomNumberIds.value))
  parts.push(...(opts.wilayas ?? []).filter((w) => wilayaIds.value.includes(w.id)).map((w) => w.name))
  parts.push(...(opts.communes ?? []).filter((c) => communeIds.value.includes(c.id)).map((c) => c.name))
  if (budgetMaxMil.value || budgetMinMil.value) {
    const min = budgetMinMil.value ? `${budgetMinMil.value}` : null
    const max = budgetMaxMil.value ? `${budgetMaxMil.value}` : null
    parts.push(min && max ? `${min} – ${max} ${MIL_LABEL}` : `${min || max} ${MIL_LABEL}`)
  }
  return parts
})

const canSubmit = computed(() => name.value.trim() !== '' && phone.value.trim() !== '')

function next() {
  if (step.value < STEPS) step.value += 1
}

function back() {
  error.value = null
  if (step.value > 1) step.value -= 1
}

async function submit() {
  if (!canSubmit.value) return
  error.value = null
  sending.value = true
  try {
    await showcase.submitLead({
      name: name.value.trim(),
      phone: phone.value.trim(),
      message: message.value.trim() || null,
      type: 'desire',
      type_ids: typeIds.value,
      room_number_ids: roomNumberIds.value,
      wilaya_ids: wilayaIds.value,
      commune_ids: communeIds.value,
      budget_min: milToDzd(budgetMinMil.value),
      budget_max: milToDzd(budgetMaxMil.value),
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
  <div v-if="sent" class="flex flex-col items-center py-10 text-center">
    <span class="flex h-16 w-16 items-center justify-center rounded-full bg-success/10 text-success">
      <i class="pi pi-check text-3xl" aria-hidden="true" />
    </span>
    <h3 class="mt-4 text-xl font-semibold text-ink">{{ $t('showcase.desire.sentTitle') }}</h3>
    <p class="mt-2 max-w-md text-sm text-mute">{{ $t('showcase.desire.sentBody') }}</p>
    <RouterLink :to="{ name: 'showcase.projects' }" class="mt-6">
      <Button :label="$t('showcase.featured.viewAll')" outlined icon="pi pi-arrow-right" icon-pos="right" />
    </RouterLink>
  </div>

  <form v-else class="relative" @submit.prevent="step === STEPS ? submit() : next()">
    <!-- Progress -->
    <div class="flex items-center gap-3">
      <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-line">
        <div
          class="h-full rounded-full bg-primary-500 transition-all duration-300"
          :style="{ width: `${(step / STEPS) * 100}%` }"
        />
      </div>
      <span class="num shrink-0 text-xs font-medium text-mute">{{ step }}/{{ STEPS }}</span>
    </div>

    <!-- Step 1 — what -->
    <div v-if="step === 1" class="mt-6 flex flex-col gap-6">
      <div>
        <h3 class="text-lg font-semibold text-ink">{{ $t('showcase.desire.step1Title') }}</h3>
        <p class="mt-1 text-sm text-mute">{{ $t('showcase.desire.step1Hint') }}</p>
      </div>

      <div v-if="options?.types?.length" class="flex flex-col gap-2">
        <span class="text-sm font-medium text-ink">{{ $t('showcase.desire.propertyType') }}</span>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="item in options.types"
            :key="item.id"
            type="button"
            class="rounded-full border px-4 py-2 text-sm font-medium transition-colors"
            :class="typeIds.includes(item.id)
              ? 'border-primary-500 bg-primary-500 text-primary-contrast'
              : 'border-line bg-card text-ink hover:border-primary-500/50'"
            :aria-pressed="typeIds.includes(item.id)"
            @click="toggle(typeIds, item.id)"
          >
            {{ itemLabel(item) }}
          </button>
        </div>
      </div>

      <div v-if="options?.room_numbers?.length" class="flex flex-col gap-2">
        <span class="text-sm font-medium text-ink">{{ $t('showcase.desire.rooms') }}</span>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="item in options.room_numbers"
            :key="item.id"
            type="button"
            class="min-w-14 rounded-full border px-4 py-2 text-sm font-medium transition-colors"
            :class="roomNumberIds.includes(item.id)
              ? 'border-primary-500 bg-primary-500 text-primary-contrast'
              : 'border-line bg-card text-ink hover:border-primary-500/50'"
            :aria-pressed="roomNumberIds.includes(item.id)"
            @click="toggle(roomNumberIds, item.id)"
          >
            {{ itemLabel(item) }}
          </button>
        </div>
      </div>

      <div v-if="showcase.loadingDesireOptions" class="space-y-3">
        <div v-for="n in 2" :key="n" class="h-16 animate-pulse rounded-xl bg-ground" />
      </div>
    </div>

    <!-- Step 2 — where & budget -->
    <div v-else-if="step === 2" class="mt-6 flex flex-col gap-5">
      <div>
        <h3 class="text-lg font-semibold text-ink">{{ $t('showcase.desire.step2Title') }}</h3>
        <p class="mt-1 text-sm text-mute">{{ $t('showcase.desire.step2Hint') }}</p>
      </div>

      <div class="flex flex-col gap-1.5">
        <label for="desire-wilayas" class="text-sm font-medium text-ink">{{ $t('showcase.desire.wilayas') }}</label>
        <MultiSelect
          v-model="wilayaIds"
          input-id="desire-wilayas"
          :options="wilayaOptions"
          option-label="name"
          option-value="id"
          filter
          :max-selected-labels="3"
          :selection-limit="5"
          :placeholder="$t('showcase.desire.anyWilaya')"
          :show-toggle-all="false"
          fluid
          @change="onWilayasChange"
        />
      </div>

      <div v-if="wilayaIds.length" class="flex flex-col gap-1.5">
        <label for="desire-communes" class="text-sm font-medium text-ink">{{ $t('showcase.desire.communes') }}</label>
        <MultiSelect
          v-model="communeIds"
          input-id="desire-communes"
          :options="communeOptions"
          option-label="name"
          option-value="id"
          filter
          :max-selected-labels="3"
          :selection-limit="10"
          :placeholder="$t('showcase.desire.anyCommune')"
          :show-toggle-all="false"
          fluid
        />
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div class="flex flex-col gap-1.5">
          <label for="desire-budget-min" class="text-sm font-medium text-ink">
            {{ $t('showcase.desire.budgetMin') }} <span class="text-mute">({{ MIL_LABEL }})</span>
          </label>
          <InputNumber
            v-model="budgetMinMil"
            input-id="desire-budget-min"
            :min="0"
            :max-fraction-digits="1"
            :suffix="` ${MIL_LABEL}`"
            fluid
          />
        </div>
        <div class="flex flex-col gap-1.5">
          <label for="desire-budget-max" class="text-sm font-medium text-ink">
            {{ $t('showcase.desire.budgetMax') }} <span class="text-mute">({{ MIL_LABEL }})</span>
          </label>
          <InputNumber
            v-model="budgetMaxMil"
            input-id="desire-budget-max"
            :min="0"
            :max-fraction-digits="1"
            :suffix="` ${MIL_LABEL}`"
            fluid
          />
        </div>
      </div>
      <p class="text-xs text-mute">{{ $t('showcase.desire.budgetHint') }}</p>
    </div>

    <!-- Step 3 — contact -->
    <div v-else class="mt-6 flex flex-col gap-4">
      <div>
        <h3 class="text-lg font-semibold text-ink">{{ $t('showcase.desire.step3Title') }}</h3>
        <p class="mt-1 text-sm text-mute">{{ $t('showcase.desire.step3Hint') }}</p>
      </div>

      <div v-if="recap.length" class="flex flex-wrap gap-1.5">
        <span
          v-for="(part, i) in recap"
          :key="i"
          class="rounded-full bg-primary-500/10 px-3 py-1 text-xs font-medium text-primary-700 dark:text-primary-300"
        >{{ part }}</span>
      </div>

      <div class="flex flex-col gap-1.5">
        <label for="desire-name" class="text-sm font-medium text-ink"
          >{{ $t('showcase.lead.name') }}<span class="text-danger" aria-hidden="true"> *</span></label
        >
        <InputText id="desire-name" v-model="name" required :maxlength="120" fluid autocomplete="name" />
      </div>

      <div class="flex flex-col gap-1.5">
        <label for="desire-phone" class="text-sm font-medium text-ink"
          >{{ $t('showcase.lead.phone') }}<span class="text-danger" aria-hidden="true"> *</span></label
        >
        <InputText
          id="desire-phone"
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

      <div class="flex flex-col gap-1.5">
        <label for="desire-message" class="text-sm font-medium text-ink">{{ $t('showcase.desire.message') }}</label>
        <Textarea
          id="desire-message"
          v-model="message"
          rows="3"
          :maxlength="2000"
          auto-resize
          fluid
          :placeholder="$t('showcase.desire.messagePlaceholder')"
        />
      </div>
    </div>

    <!-- Honeypot: invisible to humans, irresistible to bots. -->
    <div class="pointer-events-none absolute -start-[9999px] top-auto h-px w-px overflow-hidden" aria-hidden="true">
      <label for="desire-website">Website</label>
      <input id="desire-website" v-model="honeypot" type="text" tabindex="-1" autocomplete="off" />
    </div>

    <p v-if="error" class="mt-4 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">{{ error }}</p>

    <!-- Nav -->
    <div class="mt-6 flex items-center gap-2">
      <Button
        v-if="step > 1"
        type="button"
        :label="$t('showcase.desire.back')"
        severity="secondary"
        outlined
        icon="pi pi-arrow-left"
        @click="back"
      />
      <Button
        v-if="step < STEPS"
        type="submit"
        :label="$t('showcase.desire.next')"
        icon="pi pi-arrow-right"
        icon-pos="right"
        class="ms-auto"
      />
      <Button
        v-else
        type="submit"
        :label="sending ? $t('showcase.lead.sending') : $t('showcase.desire.submit')"
        :loading="sending"
        :disabled="!canSubmit"
        icon="pi pi-send"
        icon-pos="right"
        class="ms-auto"
      />
    </div>

    <p v-if="step === STEPS" class="mt-4 text-center text-xs text-mute">{{ $t('showcase.lead.privacy') }}</p>
  </form>
</template>
