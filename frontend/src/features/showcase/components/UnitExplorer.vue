<script setup>
import { computed, ref } from 'vue'
import Button from 'primevue/button'
import Select from 'primevue/select'
import ToggleButton from 'primevue/togglebutton'
import { useI18n } from 'vue-i18n'
import { formatMoney } from '@/features/payments/money'

// The interactive availability explorer — the signature feature of top
// promoter sites. Filters run client-side over the public units payload
// (binary `available`, localized labels, prices only when the project
// publishes them).

const props = defineProps({
  units: { type: Array, required: true },
  showPrices: { type: Boolean, default: false },
})

const emit = defineEmits(['interested'])

const { t } = useI18n()

const rooms = ref(null)
const floor = ref(null)
const availableOnly = ref(true)

const roomsOptions = computed(() => {
  const seen = new Map()
  for (const u of props.units) {
    if (u.rooms_id && !seen.has(u.rooms_id)) seen.set(u.rooms_id, { label: u.rooms, value: u.rooms_id })
  }
  return [...seen.values()].sort((a, b) => String(a.label).localeCompare(String(b.label)))
})

const floorOptions = computed(() => {
  const seen = new Map()
  for (const u of props.units) {
    if (u.floor_id && !seen.has(u.floor_id)) seen.set(u.floor_id, { label: u.floor, value: u.floor_id })
  }
  return [...seen.values()]
})

const filtered = computed(() =>
  props.units.filter(
    (u) =>
      (!rooms.value || u.rooms_id === rooms.value) &&
      (!floor.value || u.floor_id === floor.value) &&
      (!availableOnly.value || u.available),
  ),
)

const availableCount = computed(() => props.units.filter((u) => u.available).length)

function priceLine(unit) {
  const parts = []
  if (unit.price_semi_fini) parts.push(`${formatMoney(unit.price_semi_fini)} · ${t('showcase.units.semiFini')}`)
  if (unit.price_fini) parts.push(`${formatMoney(unit.price_fini)} · ${t('showcase.units.fini')}`)
  return parts
}
</script>

<template>
  <section id="units" class="scroll-mt-20">
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <p class="text-sm font-semibold uppercase tracking-widest text-primary-500">
          {{ $t('showcase.units.kicker') }}
        </p>
        <h2 class="mt-2 text-2xl font-bold text-ink">
          {{ $t('showcase.units.title') }}
          <span class="num ms-2 rounded-full bg-primary-500/10 px-3 py-1 text-sm font-semibold text-primary-600 dark:text-primary-400">
            {{ $t('showcase.units.availableCount', { n: availableCount }) }}
          </span>
        </h2>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <Select
          v-model="rooms"
          :options="roomsOptions"
          option-label="label"
          option-value="value"
          show-clear
          :placeholder="$t('showcase.units.filterRooms')"
          class="w-40"
          size="small"
        />
        <Select
          v-model="floor"
          :options="floorOptions"
          option-label="label"
          option-value="value"
          show-clear
          :placeholder="$t('showcase.units.filterFloor')"
          class="w-40"
          size="small"
        />
        <ToggleButton
          v-model="availableOnly"
          :on-label="$t('showcase.units.availableOnly')"
          :off-label="$t('showcase.units.all')"
          size="small"
        />
      </div>
    </div>

    <div v-if="filtered.length" class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <article
        v-for="unit in filtered"
        :key="unit.id"
        class="flex flex-col rounded-2xl border border-line bg-card p-5 shadow-card transition-shadow hover:shadow-pop"
        :class="unit.available ? '' : 'opacity-60'"
      >
        <div class="flex items-start justify-between gap-3">
          <div>
            <h3 class="font-semibold text-ink">{{ unit.rooms || $t('showcase.units.apartment') }}</h3>
            <p class="mt-0.5 text-xs text-mute">
              {{ [unit.floor, unit.block ? $t('showcase.units.block', { block: unit.block }) : null].filter(Boolean).join(' · ') }}
            </p>
          </div>
          <span
            class="flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
            :class="unit.available
              ? 'bg-success/10 text-success'
              : 'bg-surface-200 text-mute dark:bg-surface-800'"
          >
            <span class="h-1.5 w-1.5 rounded-full" :class="unit.available ? 'bg-success' : 'bg-mute'" aria-hidden="true" />
            {{ unit.available ? $t('showcase.units.statusAvailable') : $t('showcase.units.statusTaken') }}
          </span>
        </div>

        <dl class="mt-4 flex items-center gap-5 text-sm">
          <div v-if="unit.area_sqm" class="flex items-center gap-1.5">
            <i class="pi pi-expand text-mute" aria-hidden="true" />
            <span class="num font-medium text-ink">{{ Number(unit.area_sqm) }} m²</span>
          </div>
          <div v-if="unit.rooms" class="flex items-center gap-1.5">
            <i class="pi pi-home text-mute" aria-hidden="true" />
            <span class="font-medium text-ink">{{ unit.rooms }}</span>
          </div>
        </dl>

        <div v-if="showPrices && priceLine(unit).length" class="mt-3 space-y-1">
          <p v-for="line in priceLine(unit)" :key="line" class="num text-sm font-semibold text-primary-600 dark:text-primary-400">
            {{ line }}
          </p>
        </div>
        <p v-else-if="!showPrices" class="mt-3 text-sm font-medium text-mute">
          {{ $t('showcase.projects.priceOnRequest') }}
        </p>

        <Button
          v-if="unit.available"
          :label="$t('showcase.units.interested')"
          icon="pi pi-heart"
          size="small"
          rounded
          class="mt-4 self-start"
          @click="emit('interested', unit)"
        />
      </article>
    </div>

    <p v-else class="mt-6 rounded-xl border border-line bg-card p-8 text-center text-mute">
      {{ $t('showcase.units.empty') }}
    </p>
  </section>
</template>
