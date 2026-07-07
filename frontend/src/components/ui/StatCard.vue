<script setup>
import { computed } from 'vue'
import Skeleton from 'primevue/skeleton'

// KPI tile: icon chip, small label, big tabular number, optional hint line.
// The value NEVER truncates — a cut-off "1 80…" price or "Grou…" floor reads
// as missing data on a phone. Long values step the font down and wrap instead.
const TONES = {
  default: 'bg-highlight text-primary-700 dark:text-primary-300',
  success: 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300',
  warning: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
  danger: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
  info: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
}

const props = defineProps({
  label: { type: String, required: true },
  value: { type: [String, Number], default: '—' },
  icon: { type: String, default: 'pi pi-chart-bar' },
  tone: { type: String, default: 'default' },
  hint: { type: String, default: null },
  loading: { type: Boolean, default: false },
})

const valueSize = computed(() => {
  const len = String(props.value ?? '').length
  if (len > 18) return 'text-base leading-6'
  if (len > 11) return 'text-xl leading-7'
  return 'text-[26px] leading-9'
})
</script>

<template>
  <div class="rounded-xl border border-line bg-card p-4 shadow-card">
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <p class="text-[13px] font-medium text-mute">{{ label }}</p>
        <Skeleton v-if="loading" width="5rem" height="1.9rem" class="mt-1.5" />
        <p
          v-else
          class="num mt-0.5 break-words font-semibold text-ink [overflow-wrap:anywhere]"
          :class="valueSize"
        >
          {{ value }}
        </p>
        <p v-if="hint && !loading" class="mt-0.5 truncate text-xs text-mute">{{ hint }}</p>
      </div>
      <span
        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg"
        :class="TONES[tone] ?? TONES.default"
      >
        <i :class="icon" aria-hidden="true" />
      </span>
    </div>
  </div>
</template>
