<script setup>
import { computed, ref, watch } from 'vue'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import { useI18n } from 'vue-i18n'
import { MIL, MIL_LABEL, formatMoney } from '@/features/payments/money'

// Client-side payment simulator — informational only. Mirrors the CRM's
// schedule rule exactly (SaveSchedule: the plan must sum to the price to the
// dinar): integer-DZD math, equal installments floored, the LAST installment
// absorbs the remainder. No floats anywhere near the money.

const props = defineProps({
  units: { type: Array, required: true }, // available units with public prices
})

const { t } = useI18n()

const unitId = ref(props.units[0]?.id ?? null)
watch(
  () => props.units,
  (list) => {
    if (!list.some((u) => u.id === unitId.value)) unitId.value = list[0]?.id ?? null
  },
)

const unit = computed(() => props.units.find((u) => u.id === unitId.value) ?? null)

const unitOptions = computed(() =>
  props.units.map((u) => ({
    label: [u.rooms, u.floor, u.area_sqm ? `${Number(u.area_sqm)} m²` : null].filter(Boolean).join(' · ') || u.reference,
    value: u.id,
  })),
)

const finish = ref(null)
const finishOptions = computed(() => {
  if (!unit.value) return []
  return [
    unit.value.price_semi_fini ? { label: t('showcase.units.semiFini'), value: 'semi_fini' } : null,
    unit.value.price_fini ? { label: t('showcase.units.fini'), value: 'fini' } : null,
  ].filter(Boolean)
})
watch(
  finishOptions,
  (opts) => {
    if (!opts.some((o) => o.value === finish.value)) finish.value = opts[0]?.value ?? null
  },
  { immediate: true },
)

// Whole-DZD price of the chosen unit at the chosen finish.
const price = computed(() => {
  if (!unit.value || !finish.value) return 0
  const raw = finish.value === 'semi_fini' ? unit.value.price_semi_fini : unit.value.price_fini
  return Math.round(Number(raw ?? 0))
})

const downMil = ref(0)
const months = ref(24)
const monthOptions = [6, 12, 18, 24, 36, 48, 60].map((n) => ({ label: `${n}`, value: n }))

// Down payment enters in Mil (the market's unit); clamp to [0, price].
const down = computed(() => Math.min(Math.max(0, Math.round((downMil.value || 0) * MIL)), price.value))
const remaining = computed(() => price.value - down.value)

const schedule = computed(() => {
  const n = months.value
  if (!n || remaining.value <= 0) return { base: 0, last: 0, rows: [] }
  const base = Math.floor(remaining.value / n)
  const last = remaining.value - base * (n - 1)
  return { base, last, rows: n }
})

// The invariant the CRM enforces server-side, surfaced to the visitor.
const total = computed(() => down.value + schedule.value.base * (months.value - 1) + schedule.value.last)
</script>

<template>
  <section class="rounded-3xl border border-line bg-card p-6 shadow-card sm:p-8">
    <p class="text-sm font-semibold uppercase tracking-widest text-primary-500">
      {{ $t('showcase.simulator.kicker') }}
    </p>
    <h2 class="mt-2 text-2xl font-bold text-ink">{{ $t('showcase.simulator.title') }}</h2>
    <p class="mt-2 text-sm text-mute">{{ $t('showcase.simulator.subtitle') }}</p>

    <div class="mt-7 grid gap-8 lg:grid-cols-2">
      <!-- Inputs -->
      <div class="space-y-5">
        <div class="flex flex-col gap-1.5">
          <label class="text-sm font-medium text-ink" for="sim-unit">{{ $t('showcase.simulator.unit') }}</label>
          <Select id="sim-unit" v-model="unitId" :options="unitOptions" option-label="label" option-value="value" fluid />
        </div>

        <div v-if="finishOptions.length > 1" class="flex flex-col gap-1.5">
          <span class="text-sm font-medium text-ink">{{ $t('showcase.simulator.finish') }}</span>
          <SelectButton v-model="finish" :options="finishOptions" option-label="label" option-value="value" :allow-empty="false" />
        </div>

        <div class="flex flex-col gap-1.5">
          <label class="text-sm font-medium text-ink" for="sim-down">
            {{ $t('showcase.simulator.downPayment') }} <span class="text-mute">({{ MIL_LABEL }})</span>
          </label>
          <InputNumber
            id="sim-down"
            v-model="downMil"
            :min="0"
            :max-fraction-digits="1"
            :suffix="` ${MIL_LABEL}`"
            fluid
          />
        </div>

        <div class="flex flex-col gap-1.5">
          <label class="text-sm font-medium text-ink" for="sim-months">{{ $t('showcase.simulator.months') }}</label>
          <Select id="sim-months" v-model="months" :options="monthOptions" option-label="label" option-value="value" fluid />
        </div>
      </div>

      <!-- Result -->
      <div class="flex flex-col rounded-2xl bg-ground p-6">
        <div class="flex items-baseline justify-between gap-3">
          <span class="text-sm text-mute">{{ $t('showcase.simulator.price') }}</span>
          <span class="num text-lg font-bold text-ink">{{ formatMoney(price) }}</span>
        </div>
        <div class="mt-2 flex items-baseline justify-between gap-3">
          <span class="text-sm text-mute">{{ $t('showcase.simulator.downLabel') }}</span>
          <span class="num font-semibold text-ink">{{ formatMoney(down) }}</span>
        </div>

        <div class="my-5 border-t border-dashed border-line" aria-hidden="true" />

        <p class="text-sm text-mute">{{ $t('showcase.simulator.monthly', { n: months }) }}</p>
        <p class="num mt-1 text-3xl font-bold text-primary-600 dark:text-primary-400">
          {{ formatMoney(schedule.base) }}
        </p>
        <p v-if="schedule.last !== schedule.base && remaining > 0" class="mt-1.5 text-xs text-mute">
          {{ $t('showcase.simulator.lastInstallment') }}
          <span class="num font-medium">{{ formatMoney(schedule.last) }}</span>
        </p>

        <div class="mt-4 flex items-baseline justify-between gap-3 border-t border-line pt-4">
          <span class="text-sm text-mute">{{ $t('showcase.simulator.total') }}</span>
          <span class="num font-semibold" :class="total === price ? 'text-success' : 'text-ink'">
            {{ formatMoney(total) }}
          </span>
        </div>

        <p class="mt-auto pt-5 text-xs leading-relaxed text-mute">
          <i class="pi pi-info-circle me-1" aria-hidden="true" />{{ $t('showcase.simulator.disclaimer') }}
        </p>
      </div>
    </div>
  </section>
</template>
