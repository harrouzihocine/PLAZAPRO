<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { formatMoney } from '@/features/payments/money'

// The building elevation — the classic promoter-site "pick your apartment"
// visual. Each block renders as a tower of floor slabs from the top floor
// down to RDC (and basements when they exist); every floor in that range is
// drawn even when nothing is listed on it, so the visitor reads the real
// shape of the building. Available apartments are gold and link to their own
// page; sold ones stay visible in red.

const props = defineProps({
  units: { type: Array, required: true },
  showPrices: { type: Boolean, default: false },
  projectId: { type: [String, Number], required: true },
})

const { t } = useI18n()

const GROUND_RE = /rdc|rez|ground|أرضي|ارضي/i
const BASEMENT_RE = /sous[- ]?sol|basement|قبو/i

// Only show the "Unavailable" legend entry when the elevation actually has a
// parked unit — keeps the legend clean for the common all-available/sold case.
const hasUnavailable = computed(() => props.units.some((u) => u.unavailable))

// Floor number: stacking data when the CRM has it, else a best-effort read of
// the localized floor label ("RDC" → 0, "Sous-sol 1" → -1, "3e étage" → 3).
// Units with neither stay off the elevation (the UnitExplorer below always
// lists everything).
function floorNumberOf(unit) {
  if (unit.stack_floor !== null && unit.stack_floor !== undefined) return unit.stack_floor
  const label = String(unit.floor ?? '')
  if (!label) return null
  if (GROUND_RE.test(label)) return 0
  const digits = label.match(/\d+/)
  if (BASEMENT_RE.test(label)) return digits ? -Number(digits[0]) : -1
  return digits ? Number(digits[0]) : null
}

// [{ block, floors: [{ n, chip, title, units }] top-first, available }]
const blocks = computed(() => {
  const byBlock = new Map()
  for (const unit of props.units) {
    const n = floorNumberOf(unit)
    if (n === null) continue
    const key = unit.block ?? ''
    if (!byBlock.has(key)) byBlock.set(key, [])
    byBlock.get(key).push({ unit, n })
  }

  return [...byBlock.entries()]
    .sort(([a], [b]) => String(a).localeCompare(String(b)))
    .map(([block, placed]) => {
      // Always span RDC → top floor; basements only when units live there.
      const top = Math.max(0, ...placed.map((p) => p.n))
      const bottom = Math.min(0, ...placed.map((p) => p.n))
      const floors = []
      for (let n = top; n >= bottom; n--) {
        const units = placed
          .filter((p) => p.n === n)
          .map((p) => p.unit)
          .sort(
            (a, b) =>
              (a.position ?? 999) - (b.position ?? 999) ||
              String(a.reference ?? '').localeCompare(String(b.reference ?? '')),
          )
        floors.push({
          n,
          chip: n === 0 ? t('showcase.grid.rdc') : n < 0 ? t('showcase.grid.basement', { n: -n }) : String(n),
          title: units[0]?.floor || `${t('showcase.grid.floor')} ${n}`,
          units,
        })
      }
      return { block, floors, available: placed.filter((p) => p.unit.available).length }
    })
})

function unitStatusLabel(unit) {
  if (unit.available) return t('showcase.units.statusAvailable')
  // Parked off the market by the promoteur — greyed, distinct from a red "sold".
  return unit.unavailable ? t('showcase.grid.unavailable') : t('showcase.grid.sold')
}

function cellTitle(unit) {
  return [
    unit.reference,
    unit.rooms,
    unit.floor,
    unit.area_sqm ? `${Number(unit.area_sqm)} m²` : null,
    props.showPrices && (unit.price_semi_fini || unit.price_fini)
      ? formatMoney(unit.price_semi_fini ?? unit.price_fini)
      : null,
    unitStatusLabel(unit),
  ].filter(Boolean).join(' · ')
}
</script>

<template>
  <section v-if="blocks.length" class="rounded-3xl border border-line bg-card p-4 shadow-card sm:p-8">
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
          <!-- Solid bg-danger: the token is a plain CSS var, /opacity modifiers compile to nothing -->
          <span class="h-3 w-3 rounded bg-danger" aria-hidden="true" />{{ $t('showcase.grid.sold') }}
        </span>
        <span v-if="hasUnavailable" class="flex items-center gap-1.5">
          <span class="h-3 w-3 rounded bg-surface-300 dark:bg-surface-700" aria-hidden="true" />{{ $t('showcase.grid.unavailable') }}
        </span>
      </div>
    </div>

    <div class="mt-8 grid gap-x-8 gap-y-10" :class="blocks.length > 1 ? 'sm:grid-cols-2' : ''">
      <!-- min-w-0: a grid item's min-width:auto makes the implicit track grow
           to the widest row, blowing the page out sideways instead of letting
           the scroller scroll. -->
      <div
        v-for="group in blocks"
        :key="group.block"
        class="min-w-0"
        :class="blocks.length === 1 ? 'mx-auto w-full lg:max-w-3xl' : ''"
      >
        <div class="mb-3 flex items-center justify-between gap-3">
          <h3 class="text-sm font-semibold text-ink">
            {{ group.block ? $t('showcase.units.block', { block: group.block }) : ' ' }}
          </h3>
          <span class="num rounded-full bg-primary-500/10 px-2.5 py-0.5 text-xs font-semibold text-primary-600 dark:text-primary-400">
            {{ $t('showcase.units.availableCount', { n: group.available }) }}
          </span>
        </div>

        <!-- The tower: roofline, one slab per floor top-down, ground shadow -->
        <div class="overflow-hidden rounded-2xl border border-line bg-card shadow-card">
          <div class="h-1.5 bg-gradient-to-r from-primary-500/10 via-primary-500/70 to-primary-500/10" aria-hidden="true" />

          <!-- Horizontal scroll guards wide floors (scroll shadows below) -->
          <div class="grid-scroller overflow-x-auto">
            <div class="inline-flex min-w-full flex-col divide-y divide-line/60">
              <div v-for="row in group.floors" :key="row.n" class="flex items-stretch">
                <!-- Floor chip: sticky so the level stays readable mid-scroll -->
                <div class="sticky start-0 z-10 flex items-center bg-card py-1.5 ps-2 pe-2 sm:ps-3">
                  <span
                    class="num flex h-10 w-9 shrink-0 items-center justify-center rounded-lg text-[11px] font-bold sm:h-11 sm:w-10"
                    :class="row.n === 0 ? 'bg-primary-500/10 text-primary-600 dark:text-primary-400' : 'bg-ground text-mute'"
                    :title="row.title"
                  >
                    {{ row.chip }}
                  </span>
                </div>

                <div class="flex flex-1 items-center gap-1.5 py-1.5 pe-2 sm:pe-3">
                  <template v-if="row.units.length">
                    <template v-for="unit in row.units" :key="unit.id">
                      <!-- Available: the whole cell is a door into the unit's page -->
                      <RouterLink
                        v-if="unit.available"
                        :to="{ name: 'showcase.unit', params: { id: projectId, unitId: unit.id } }"
                        class="flex h-10 min-w-14 shrink-0 flex-col items-center justify-center rounded-lg bg-primary-500 px-2 text-primary-contrast shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-pop focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 focus-visible:ring-offset-card sm:h-11 sm:min-w-16"
                        :title="cellTitle(unit)"
                        :aria-label="cellTitle(unit)"
                      >
                        <span class="text-[11px] font-bold leading-tight">{{ unit.rooms || unit.reference || '·' }}</span>
                        <span v-if="unit.area_sqm" class="num text-[9px] font-medium leading-tight opacity-85">
                          {{ Number(unit.area_sqm) }} m²
                        </span>
                      </RouterLink>

                      <!-- Unavailable: parked off the market — greyed, non-clickable -->
                      <span
                        v-else-if="unit.unavailable"
                        class="flex h-10 min-w-14 shrink-0 flex-col items-center justify-center rounded-lg bg-surface-200 px-2 text-mute dark:bg-surface-800 sm:h-11 sm:min-w-16"
                        :title="cellTitle(unit)"
                      >
                        <span class="text-[11px] font-bold leading-tight">
                          {{ unit.rooms || unit.reference || '·' }}
                        </span>
                        <span class="text-[9px] font-semibold uppercase leading-tight tracking-wide">
                          {{ $t('showcase.grid.unavailable') }}
                        </span>
                      </span>

                      <!-- Sold: stays on the facade, in red -->
                      <span
                        v-else
                        class="flex h-10 min-w-14 shrink-0 flex-col items-center justify-center rounded-lg bg-danger px-2 text-white sm:h-11 sm:min-w-16"
                        :title="cellTitle(unit)"
                      >
                        <span class="text-[11px] font-bold leading-tight line-through decoration-white/70">
                          {{ unit.rooms || unit.reference || '·' }}
                        </span>
                        <span class="text-[9px] font-semibold uppercase leading-tight tracking-wide">
                          {{ $t('showcase.grid.sold') }}
                        </span>
                      </span>
                    </template>
                  </template>

                  <!-- A floor with nothing listed still shows as part of the building -->
                  <div
                    v-else
                    class="flex h-10 min-w-40 flex-1 items-center justify-center rounded-lg border border-dashed border-line text-[10px] font-medium uppercase tracking-wide text-mute/70 sm:h-11"
                  >
                    {{ $t('showcase.grid.emptyFloor') }}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="mx-auto mt-1.5 h-1 w-2/3 rounded-full bg-line/80" aria-hidden="true" />
      </div>
    </div>
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
</style>
