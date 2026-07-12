<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatMoney } from '@/features/payments/money'

// The building at a glance — the classic promoter-site "pick your apartment"
// visual. Units group by block, stack top-floor-first, and sit at their
// `position` column; clicking an available cell hands the unit to the lead
// flow. Units without stacking coordinates simply don't appear here (the
// UnitExplorer list below always shows everything).

const props = defineProps({
  units: { type: Array, required: true },
  showPrices: { type: Boolean, default: false },
})

const emit = defineEmits(['interested'])

const { t } = useI18n()

const placeable = computed(() =>
  props.units.filter((u) => u.stack_floor !== null && u.position !== null),
)

// Grid data: [{ block, floors: [{ floor, cells: [{unit|null} per column] }], columns }]
const blocks = computed(() => {
  const byBlock = new Map()
  for (const unit of placeable.value) {
    const key = unit.block ?? ''
    if (!byBlock.has(key)) byBlock.set(key, [])
    byBlock.get(key).push(unit)
  }

  return [...byBlock.entries()]
    .sort(([a], [b]) => String(a).localeCompare(String(b)))
    .map(([block, units]) => {
      // Trim columns to the positions the block actually uses: real stacking
      // data is sparse (units at positions 4-6 only), and rendering from
      // column 1 fills phones with dashed ghost cells and forces h-scroll.
      // Interior gaps stay — they are the true shape of the building.
      const first = Math.min(...units.map((u) => u.position))
      const last = Math.max(...units.map((u) => u.position))
      const floors = [...new Set(units.map((u) => u.stack_floor))]
        .sort((a, b) => b - a) // top floor first, like a real building
        .map((floor) => ({
          floor,
          cells: Array.from({ length: last - first + 1 }, (_, i) =>
            units.find((u) => u.stack_floor === floor && u.position === first + i) ?? null),
        }))
      return { block, floors, columns: last - first + 1 }
    })
})

const selected = ref(null)

function pick(unit) {
  selected.value = selected.value?.id === unit.id ? null : unit
}

function cellTitle(unit) {
  return [
    unit.rooms,
    unit.floor,
    unit.area_sqm ? `${Number(unit.area_sqm)} m²` : null,
    props.showPrices && (unit.price_semi_fini || unit.price_fini)
      ? formatMoney(unit.price_semi_fini ?? unit.price_fini)
      : null,
    unit.available ? t('showcase.units.statusAvailable') : t('showcase.units.statusTaken'),
  ].filter(Boolean).join(' · ')
}
</script>

<template>
  <section v-if="placeable.length" class="rounded-3xl border border-line bg-card p-4 shadow-card sm:p-8">
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <p class="text-sm font-semibold uppercase tracking-widest text-primary-500">
          {{ $t('showcase.grid.kicker') }}
        </p>
        <h2 class="mt-2 text-2xl font-bold text-ink">{{ $t('showcase.grid.title') }}</h2>
      </div>

      <!-- Legend -->
      <div class="flex items-center gap-4 text-xs text-mute">
        <span class="flex items-center gap-1.5">
          <span class="h-3 w-3 rounded bg-primary-500" aria-hidden="true" />{{ $t('showcase.units.statusAvailable') }}
        </span>
        <span class="flex items-center gap-1.5">
          <span class="h-3 w-3 rounded bg-surface-300 dark:bg-surface-700" aria-hidden="true" />{{ $t('showcase.units.statusTaken') }}
        </span>
      </div>
    </div>

    <div class="mt-6 grid gap-x-8 gap-y-6 sm:gap-y-8" :class="blocks.length > 1 ? 'sm:grid-cols-2' : ''">
      <!-- min-w-0: a grid item's min-width:auto makes the implicit track grow
           to the widest row, blowing the page out sideways instead of letting
           the scroller scroll. -->
      <div v-for="group in blocks" :key="group.block" class="min-w-0">
        <h3 v-if="group.block" class="mb-3 text-sm font-semibold text-mute">
          {{ $t('showcase.units.block', { block: group.block }) }}
        </h3>

        <!-- One row per floor, top down; horizontal scroll guards wide blocks -->
        <div class="grid-scroller overflow-x-auto pb-1">
          <div class="inline-flex min-w-full flex-col gap-1.5">
            <div v-for="row in group.floors" :key="row.floor" class="flex items-center gap-1 sm:gap-1.5">
              <span class="num w-5 shrink-0 text-end text-[10px] text-mute sm:w-7 sm:text-xs" :title="$t('showcase.grid.floor')">
                {{ row.floor }}
              </span>
              <template v-for="(unit, i) in row.cells" :key="i">
                <button
                  v-if="unit"
                  type="button"
                  class="h-9 w-11 shrink-0 rounded-md text-[10px] font-semibold transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 sm:h-10 sm:w-14 sm:text-[11px]"
                  :class="[
                    unit.available
                      ? 'bg-primary-500 text-primary-contrast hover:scale-105 hover:shadow-pop'
                      : 'cursor-default bg-surface-300 text-surface-500 dark:bg-surface-700 dark:text-surface-400',
                    selected?.id === unit.id ? 'ring-2 ring-ink ring-offset-2 ring-offset-card' : '',
                  ]"
                  :title="cellTitle(unit)"
                  :disabled="!unit.available"
                  @click="pick(unit)"
                >
                  {{ unit.rooms || '·' }}
                </button>
                <span v-else class="h-9 w-11 shrink-0 rounded-md border border-dashed border-line sm:h-10 sm:w-14" aria-hidden="true" />
              </template>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Selected-unit tray -->
    <Transition name="showcase-tray">
      <div
        v-if="selected"
        class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-ground p-4"
      >
        <div class="text-sm">
          <p class="font-semibold text-ink">
            {{ [selected.rooms, selected.floor].filter(Boolean).join(' · ') || $t('showcase.units.apartment') }}
            <span v-if="selected.block" class="text-mute"> — {{ $t('showcase.units.block', { block: selected.block }) }}</span>
          </p>
          <p class="mt-0.5 text-mute">
            <span v-if="selected.area_sqm" class="num">{{ Number(selected.area_sqm) }} m²</span>
            <span v-if="showPrices && (selected.price_semi_fini || selected.price_fini)" class="num ms-3 font-semibold text-primary-600 dark:text-primary-400">
              {{ formatMoney(selected.price_semi_fini ?? selected.price_fini) }}
            </span>
          </p>
        </div>
        <button
          type="button"
          class="rounded-full bg-primary-500 px-5 py-2 text-sm font-semibold text-primary-contrast transition-transform hover:scale-105"
          @click="emit('interested', selected)"
        >
          <i class="pi pi-heart me-1.5" aria-hidden="true" />{{ $t('showcase.units.interested') }}
        </button>
      </div>
    </Transition>
  </section>
</template>

<style scoped>
/* Scroll shadows: edge hints that appear only while columns are hidden on
   that side (pure CSS — the card-colored cover layers scroll with the
   content and mask the shadows at the extremes). */
.grid-scroller {
  background:
    linear-gradient(to right, var(--p-content-background) 30%, transparent),
    linear-gradient(to left, var(--p-content-background) 30%, transparent) 100% 0,
    radial-gradient(farthest-side at 0 50%, rgb(0 0 0 / 0.22), transparent),
    radial-gradient(farthest-side at 100% 50%, rgb(0 0 0 / 0.22), transparent) 100% 0;
  background-repeat: no-repeat;
  background-size:
    40px 100%,
    40px 100%,
    12px 100%,
    12px 100%;
  background-attachment: local, local, scroll, scroll;
}

.showcase-tray-enter-active,
.showcase-tray-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}
.showcase-tray-enter-from,
.showcase-tray-leave-to {
  opacity: 0;
  transform: translateY(6px);
}
</style>
