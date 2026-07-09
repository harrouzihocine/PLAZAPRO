<script setup>
import { computed } from 'vue'
import Skeleton from 'primevue/skeleton'
import { formatMoney } from '@/features/payments/money'

// A KPI tile: icon chip, label, big number, and an optional Δ-vs-previous badge.
// Money values are passed as base-DZD strings and shown in Mil. `invertDelta`
// flips the colour for metrics where a drop is good (overdue, cancellations).
const props = defineProps({
  label: { type: String, required: true },
  value: { type: [String, Number], default: '—' },
  icon: { type: String, default: 'pi pi-chart-bar' },
  tone: { type: String, default: 'default' },
  money: { type: Boolean, default: false },
  suffix: { type: String, default: '' },
  delta: { type: Number, default: null },
  invertDelta: { type: Boolean, default: false },
  hint: { type: String, default: null },
  loading: { type: Boolean, default: false },
})

const TONES = {
  default: 'bg-highlight text-primary-700 dark:text-primary-300',
  success: 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300',
  warning: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
  danger: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
  info: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
}

const display = computed(() => {
  if (props.value === null || props.value === undefined || props.value === '') return '—'
  const base = props.money ? formatMoney(props.value) : props.value
  return props.suffix ? `${base}${props.suffix}` : base
})

// Positive Δ is "good" unless inverted; null Δ means no baseline (show nothing).
const deltaGood = computed(() =>
  props.delta === null ? null : props.invertDelta ? props.delta < 0 : props.delta >= 0,
)
const valueSize = computed(() => (String(display.value).length > 12 ? 'text-xl' : 'text-2xl'))
</script>

<template>
  <div class="rounded-xl border border-line bg-card p-4 shadow-card">
    <div class="flex items-start justify-between gap-2">
      <span
        class="inline-flex h-9 w-9 items-center justify-center rounded-lg"
        :class="TONES[tone] ?? TONES.default"
      >
        <i :class="icon" aria-hidden="true" />
      </span>
      <span
        v-if="delta !== null && !loading"
        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold"
        :class="
          deltaGood
            ? 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300'
            : 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300'
        "
      >
        <i :class="delta >= 0 ? 'pi pi-arrow-up' : 'pi pi-arrow-down'" class="text-[10px]" aria-hidden="true" />
        {{ Math.abs(delta) }}%
      </span>
    </div>
    <p class="mt-3 text-[13px] font-medium text-mute">{{ label }}</p>
    <Skeleton v-if="loading" width="5rem" height="1.9rem" class="mt-1.5" />
    <p v-else class="num mt-0.5 break-words font-semibold text-ink [overflow-wrap:anywhere]" :class="valueSize">
      {{ display }}
    </p>
    <p v-if="hint && !loading" class="mt-1 text-xs text-mute">{{ hint }}</p>
  </div>
</template>
