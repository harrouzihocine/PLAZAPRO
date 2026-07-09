<script setup>
// Semi-fini / Fini switch, shown ONLY when the unit quotes BOTH prices — the
// client picks which offer applies and that finish's price becomes THE price.
// Single-price units have no choice to make, so the toggle renders nothing
// (the FinishPrices badge already says which offer it is).
import { computed } from 'vue'
import { formatMoney } from '@/features/payments/money'

const props = defineProps({
  modelValue: { type: String, default: null }, // 'semi_fini' | 'fini'
  semiFini: { type: [String, Number, null], default: null },
  fini: { type: [String, Number, null], default: null },
  disabled: { type: Boolean, default: false },
  // Include each offer's price on its chip (drop on tight layouts).
  withPrices: { type: Boolean, default: true },
})
const emit = defineEmits(['update:modelValue'])

const options = computed(() =>
  [
    { value: 'semi_fini', price: props.semiFini },
    { value: 'fini', price: props.fini },
  ].filter((o) => o.price != null),
)
</script>

<template>
  <span
    v-if="options.length > 1"
    class="inline-flex overflow-hidden rounded-lg border border-line"
    role="group"
    :aria-label="$t('inventory.finishing')"
  >
    <button
      v-for="o in options"
      :key="o.value"
      type="button"
      class="px-2.5 py-1 text-xs transition-colors disabled:cursor-not-allowed disabled:opacity-60"
      :class="
        modelValue === o.value
          ? 'bg-highlight font-semibold text-ink'
          : 'text-mute hover:text-ink'
      "
      :disabled="disabled"
      :aria-pressed="modelValue === o.value"
      @click="emit('update:modelValue', o.value)"
    >
      {{ o.value === 'semi_fini' ? $t('inventory.finishSemiFini') : $t('inventory.finishFini') }}
      <span v-if="withPrices" class="num ms-1 opacity-80">{{ formatMoney(o.price) }}</span>
    </button>
  </span>
</template>
