<script setup>
// A unit's finish-level prices: one line per offered finish, each tagged
// Semi / Fini so agents see at a glance WHAT the unit can be offered as
// (semi-finished, turnkey, or both). Reused by the units table, phone cards,
// pickers and detail pages.
import { formatMoney } from '@/features/payments/money'

defineProps({
  semiFini: { type: [String, Number, null], default: null },
  fini: { type: [String, Number, null], default: null },
  // Cards lay the two offers on one line; table cells stack them.
  inline: { type: Boolean, default: false },
  // The API masked the prices (sold unit, viewer lacks units.sold_price) —
  // show an honest lock, not a dash that reads like "no price set".
  masked: { type: Boolean, default: false },
})
</script>

<template>
  <span
    v-if="masked"
    class="inline-flex items-center gap-1.5 text-mute"
    :title="$t('inventory.priceHiddenHint')"
  >
    <i class="pi pi-lock text-xs" aria-hidden="true" />
    <span class="text-sm">{{ $t('inventory.priceHidden') }}</span>
  </span>
  <span
    v-else
    :class="inline ? 'inline-flex flex-wrap items-center gap-x-3 gap-y-0.5' : 'flex flex-col gap-0.5'"
  >
    <span v-if="semiFini != null" class="num flex items-center gap-1.5 whitespace-nowrap">
      <span
        class="rounded px-1 py-0.5 text-[10px] font-semibold uppercase tracking-wide bg-slate-100 text-slate-600 dark:bg-slate-500/15 dark:text-slate-300"
        >{{ $t('inventory.finishSemiShort') }}</span
      >
      <span class="font-semibold text-ink">{{ formatMoney(semiFini) }}</span>
    </span>
    <span v-if="fini != null" class="num flex items-center gap-1.5 whitespace-nowrap">
      <span
        class="rounded px-1 py-0.5 text-[10px] font-semibold uppercase tracking-wide bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"
        >{{ $t('inventory.finishFiniShort') }}</span
      >
      <span class="font-semibold text-ink">{{ formatMoney(fini) }}</span>
    </span>
    <span v-if="semiFini == null && fini == null" class="text-mute">—</span>
  </span>
</template>
