<script setup>
import { computed, ref } from 'vue'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import { formatMoney } from '@/features/payments/money'
import { useFavorites } from '../composables/useFavorites'

// The visitor's shortlist: a floating tray while hearts are ticked, and a
// side-by-side comparison (up to 3 apartments) on demand.

const props = defineProps({
  projectId: { type: [String, Number], required: true },
  units: { type: Array, required: true },
  showPrices: { type: Boolean, default: false },
})

const emit = defineEmits(['interested'])

const { unitIds, clear } = useFavorites(props.projectId)

// Resolve hearts against the loaded payload; stale ids (unit unpublished
// since) silently drop out.
const picked = computed(() =>
  unitIds.value.map((id) => props.units.find((u) => u.id === id)).filter(Boolean),
)

const open = ref(false)
const compared = computed(() => picked.value.slice(0, 3))

const ROWS = [
  { key: 'rooms', labelKey: 'showcase.units.filterRooms', value: (u) => u.rooms },
  { key: 'floor', labelKey: 'showcase.units.filterFloor', value: (u) => u.floor },
  { key: 'block', labelKey: 'showcase.compare.block', value: (u) => u.block },
  { key: 'area', labelKey: 'showcase.compare.area', value: (u) => (u.area_sqm ? `${Number(u.area_sqm)} m²` : null) },
]
</script>

<template>
  <!-- Floating tray -->
  <Transition name="showcase-tray">
    <div
      v-if="picked.length"
      class="fixed bottom-24 start-1/2 z-40 flex max-w-[calc(100vw-2rem)] -translate-x-1/2 items-center gap-2 rounded-full border border-line bg-card py-2 pe-2 ps-4 shadow-pop rtl:translate-x-1/2 sm:bottom-5"
    >
      <span class="num whitespace-nowrap text-sm font-semibold text-ink">{{ $t('showcase.compare.count', { n: picked.length }) }}</span>
      <Button :label="$t('showcase.compare.open')" size="small" rounded icon="pi pi-arrows-h" @click="open = true" />
      <Button
        icon="pi pi-trash"
        size="small"
        rounded
        text
        severity="secondary"
        :aria-label="$t('showcase.compare.clear')"
        @click="clear"
      />
    </div>
  </Transition>

  <!-- Comparison dialog -->
  <Dialog
    v-model:visible="open"
    modal
    dismissable-mask
    :header="$t('showcase.compare.title')"
    class="w-[52rem] max-w-[calc(100vw-2rem)]"
  >
    <p v-if="picked.length > 3" class="mb-3 text-xs text-mute">{{ $t('showcase.compare.limit') }}</p>

    <div class="overflow-x-auto">
      <table class="w-full min-w-[28rem] text-sm">
        <thead>
          <tr>
            <th class="w-28" />
            <th v-for="unit in compared" :key="unit.id" class="pb-3 text-start">
              <p class="font-semibold text-ink">{{ unit.rooms || $t('showcase.units.apartment') }}</p>
              <span
                class="mt-1 inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-semibold"
                :class="unit.available ? 'bg-success/10 text-success' : 'bg-surface-200 text-mute dark:bg-surface-800'"
              >
                <span class="h-1.5 w-1.5 rounded-full" :class="unit.available ? 'bg-success' : 'bg-mute'" aria-hidden="true" />
                {{ unit.available ? $t('showcase.units.statusAvailable') : $t('showcase.units.statusTaken') }}
              </span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in ROWS" :key="row.key" class="border-t border-line">
            <td class="py-2.5 pe-3 text-mute">{{ $t(row.labelKey) }}</td>
            <td v-for="unit in compared" :key="unit.id" class="py-2.5 font-medium text-ink">
              {{ row.value(unit) ?? '—' }}
            </td>
          </tr>
          <template v-if="showPrices">
            <tr class="border-t border-line">
              <td class="py-2.5 pe-3 text-mute">{{ $t('showcase.units.semiFini') }}</td>
              <td v-for="unit in compared" :key="unit.id" class="num py-2.5 font-semibold text-primary-600 dark:text-primary-400">
                {{ unit.price_semi_fini ? formatMoney(unit.price_semi_fini) : '—' }}
              </td>
            </tr>
            <tr class="border-t border-line">
              <td class="py-2.5 pe-3 text-mute">{{ $t('showcase.units.fini') }}</td>
              <td v-for="unit in compared" :key="unit.id" class="num py-2.5 font-semibold text-primary-600 dark:text-primary-400">
                {{ unit.price_fini ? formatMoney(unit.price_fini) : '—' }}
              </td>
            </tr>
          </template>
          <tr class="border-t border-line">
            <td />
            <td v-for="unit in compared" :key="unit.id" class="py-3">
              <Button
                v-if="unit.available"
                :label="$t('showcase.units.interested')"
                icon="pi pi-heart"
                size="small"
                rounded
                @click="open = false; emit('interested', unit)"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </Dialog>
</template>

<style scoped>
.showcase-tray-enter-active,
.showcase-tray-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}
.showcase-tray-enter-from,
.showcase-tray-leave-to {
  opacity: 0;
  transform: translate(-50%, 10px);
}
[dir='rtl'] .showcase-tray-enter-from,
[dir='rtl'] .showcase-tray-leave-to {
  transform: translate(50%, 10px);
}
</style>
