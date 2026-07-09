<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseMultiSelect from '@/components/base/BaseMultiSelect.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import MoneyInput from '@/components/base/MoneyInput.vue'
import { useDynamicList, itemLabel } from '@/composables/useDynamicList'
import { boxesApi, locationsApi, unitsApi } from '@/features/inventory/api'
import { formatMoney } from '@/features/payments/money'
import UnitBoxPicker from '@/features/inventory/components/UnitBoxPicker.vue'
import FinishToggle from '@/features/inventory/components/FinishToggle.vue'
import { t } from '@/i18n'

// The property picker used across the workflow (call log, office-visit shortlist,
// deal creation): pick a project (location), refine with the same filters as the
// inventory units screen (type / floor / price / area), then tap available units
// (and boxes, unless units-only) to multi-select them. Each candidate shows the
// FULL property card (reference · type · floor · area · price), not just its code.
// The refinements can also be set BEFORE a project is picked: they then narrow
// the project dropdown itself to projects that still have a purchasable unit
// matching them (server-side unit_* params on /locations).
//
// v-model is a list of { shortlistable_type: 'unit'|'box', shortlistable_id,
// label, location_id, box_ids?, finish_type?, price_semi_fini?, price_fini? }
// — units carry the finish the client is offered (semi_fini | fini; defaults
// to semi-fini when the unit quotes both) and both raw prices so the toggle
// can render without refetching. `exclude` hides properties already elsewhere
// (e.g. on the saved shortlist). With `with-boxes`, every selected apartment
// offers ITS boxes: the ones linked to it plus the location's unlinked pool
// (picking one links it) — never a box belonging to another apartment. Deal
// contexts pair `with-boxes` with `units-only`, so standalone boxes are not
// pickable there (a box always rides with an apartment).
const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  exclude: { type: Array, default: () => [] }, // ['unit:12', 'box:3', …]
  withBoxes: { type: Boolean, default: false },
  unitsOnly: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const { items: floors } = useDynamicList('floors')
const { items: roomNumbers } = useDynamicList('room_numbers')
const { items: projectTypes } = useDynamicList('project_types')

const locations = ref([])
const locationId = ref('')
// Narrows the PROJECT dropdown (a location attribute — every unit of a project
// shares it), unlike the unit-level refinements below.
const projectTypeId = ref('')
const units = ref([])
const boxes = ref([])
const loading = ref(false)
const showFilters = ref(false)

// Inventory-style refinements, applied server-side like UnitsView.
const filters = reactive({
  room_number_id: [],
  floor_id: [],
  min_price: '',
  max_price: '',
  min_area: '',
  max_area: '',
})

const hasUnitFilters = computed(
  () =>
    filters.room_number_id.length > 0 ||
    filters.floor_id.length > 0 ||
    ['min_price', 'max_price', 'min_area', 'max_area'].some(
      (k) => filters[k] !== '' && filters[k] != null,
    ),
)

// The refinements narrow the PROJECT list itself (unit_* params): only projects
// with at least one purchasable unit matching them stay in the dropdown.
async function loadLocations() {
  const params = {}
  if (filters.room_number_id.length) params.unit_room_number_id = filters.room_number_id
  if (filters.floor_id.length) params.unit_floor_id = filters.floor_id
  for (const k of ['min_price', 'max_price', 'min_area', 'max_area']) {
    if (filters[k] !== '' && filters[k] != null) params[`unit_${k}`] = filters[k]
  }
  locations.value = await locationsApi.list(params)
  // The picked project can drop out of the narrowed list — clear it so the
  // candidates never show units of a project the dropdown no longer offers.
  if (locationId.value && !locationOptions.value.some((o) => o.value === locationId.value)) {
    locationId.value = ''
    units.value = []
    boxes.value = []
  }
}

onMounted(loadLocations)

const locationOptions = computed(() =>
  locations.value
    .filter((l) => !projectTypeId.value || l.type_id === projectTypeId.value)
    .map((l) => ({ value: l.id, label: `${l.code} · ${l.name}` })),
)

// Changing the project type can orphan the picked project — clear it so the
// candidate list never shows units of a project the dropdown no longer offers.
watch(projectTypeId, () => {
  if (locationId.value && !locationOptions.value.some((o) => o.value === locationId.value)) {
    locationId.value = ''
    units.value = []
    boxes.value = []
  }
})

async function loadCandidates() {
  if (!locationId.value) return
  loading.value = true
  try {
    // Interested / reserved units can still be shortlisted and held as backups
    // ("2nd place") — only a sold unit is off the table. Boxes stay single-tenant
    // (available only).
    const params = {
      location_id: locationId.value,
      sale_status: ['available', 'interested', 'reserved'],
    }
    for (const [k, v] of Object.entries(filters)) {
      if (Array.isArray(v) ? v.length : v !== '' && v != null) params[k] = v
    }
    const [u, b] = await Promise.all([
      unitsApi.list(params),
      // Standalone boxes are only pickable outside deal contexts (units-only).
      props.unitsOnly
        ? Promise.resolve([])
        : boxesApi.list({ location_id: locationId.value, sale_status: 'available' }),
    ])
    units.value = u
    boxes.value = b
  } finally {
    loading.value = false
  }
}

// A filter change refreshes both the narrowed project list and, once a project
// is picked, its candidate units.
function applyFilters() {
  loadLocations()
  loadCandidates()
}

// The Mil-scaled price fields emit on every keystroke, so debounce their reload.
let priceTimer = null
function applyFiltersDebounced() {
  clearTimeout(priceTimer)
  priceTimer = setTimeout(applyFilters, 400)
}

watch(locationId, () => {
  units.value = []
  boxes.value = []
  loadCandidates()
})

const keyOf = (type, id) => `${type}:${id}`
const selectedKeys = computed(
  () => new Set(props.modelValue.map((p) => keyOf(p.shortlistable_type, p.shortlistable_id))),
)
const excludedKeys = computed(() => new Set(props.exclude))

const locationName = (id) => locations.value.find((l) => l.id === id)?.name ?? null

// One rich, human-readable card per property — all its info, not only the
// code. The project (location) name rides in the label so a selection kept
// across project switches stays unambiguous.
function unitLabel(u) {
  // Both finish offers ride in the label — "Semi 145 / Fini 178" — so the
  // agent quotes the right number without opening the unit.
  const prices = [
    u.price_semi_fini != null
      ? `${t('inventory.finishSemiShort')} ${formatMoney(u.price_semi_fini)}`
      : null,
    u.price_fini != null ? `${t('inventory.finishFiniShort')} ${formatMoney(u.price_fini)}` : null,
  ].filter(Boolean)

  return [
    u.reference,
    u.location?.name ?? locationName(u.location_id),
    u.floor,
    u.area_sqm ? `${u.area_sqm} m²` : null,
    prices.length ? prices.join(' / ') : null,
  ]
    .filter(Boolean)
    .join(' · ')
}
function boxLabel(b) {
  return [b.reference, locationName(b.location_id), b.type, b.price ? formatMoney(b.price) : null]
    .filter(Boolean)
    .join(' · ')
}

const candidates = computed(() => {
  const all = [
    ...units.value.map((u) => ({
      type: 'unit',
      id: u.id,
      label: unitLabel(u),
      locationId: u.location_id,
      // Reserved / interested still show (backups are the point) but wear the
      // status + how many reservations already queue — so the agent can tell
      // the client "you would be Nth in line".
      saleStatus: ['reserved', 'interested'].includes(u.sale_status) ? u.sale_status : null,
      queueCount: u.interested_count ?? 0,
      priceSemiFini: u.price_semi_fini ?? null,
      priceFini: u.price_fini ?? null,
    })),
    ...boxes.value.map((b) => ({
      type: 'box',
      id: b.id,
      label: boxLabel(b),
      locationId: b.location_id,
    })),
  ]
  return all.filter((c) => !excludedKeys.value.has(keyOf(c.type, c.id)))
})

function toggle(candidate) {
  const key = keyOf(candidate.type, candidate.id)
  if (selectedKeys.value.has(key)) {
    emit(
      'update:modelValue',
      props.modelValue.filter((p) => keyOf(p.shortlistable_type, p.shortlistable_id) !== key),
    )
  } else {
    emit('update:modelValue', [
      ...props.modelValue,
      {
        shortlistable_type: candidate.type,
        shortlistable_id: candidate.id,
        label: candidate.label,
        location_id: candidate.locationId,
        ...(props.withBoxes && candidate.type === 'unit' ? { box_ids: [] } : {}),
        // The finish proposed to the client — semi-fini first when both are
        // quoted; the FinishToggle on the selected card switches it.
        ...(candidate.type === 'unit'
          ? {
              finish_type: candidate.priceSemiFini != null ? 'semi_fini' : 'fini',
              price_semi_fini: candidate.priceSemiFini,
              price_fini: candidate.priceFini,
            }
          : {}),
      },
    ])
  }
}

function remove(index) {
  const next = [...props.modelValue]
  next.splice(index, 1)
  emit('update:modelValue', next)
}

// --- The box decision on a selected apartment (with-boxes mode) ---

function setBoxIds(index, boxIds) {
  const next = props.modelValue.map((p, i) => (i === index ? { ...p, box_ids: boxIds } : p))
  emit('update:modelValue', next)
}

function setFinish(index, finish) {
  const next = props.modelValue.map((p, i) => (i === index ? { ...p, finish_type: finish } : p))
  emit('update:modelValue', next)
}
</script>

<template>
  <div class="space-y-3">
    <!-- Already-selected properties — full card + the box decision per unit. -->
    <div v-if="modelValue.length" class="space-y-2">
      <div
        v-for="(p, i) in modelValue"
        :key="p.shortlistable_type + p.shortlistable_id"
        class="rounded-lg bg-highlight px-3 py-2 text-xs"
      >
        <span class="inline-flex w-full items-center gap-2">
          <i
            :class="p.shortlistable_type === 'box' ? 'pi pi-car' : 'pi pi-home'"
            class="text-[10px] text-mute"
            aria-hidden="true"
          />
          <span class="flex-1 text-ink">
            {{ p.label ?? `${p.shortlistable_type} #${p.shortlistable_id}` }}
          </span>
          <button
            type="button"
            class="text-mute hover:text-danger"
:aria-label="$t('inventory.removeProperty')"
            @click="remove(i)"
          >
            <i class="pi pi-times text-[10px]" aria-hidden="true" />
          </button>
        </span>
        <!-- Both finishes quoted → the client picks which offer applies. -->
        <div
          v-if="p.shortlistable_type === 'unit' && p.price_semi_fini != null && p.price_fini != null"
          class="mt-2"
        >
          <FinishToggle
            :model-value="p.finish_type ?? 'semi_fini'"
            :semi-fini="p.price_semi_fini"
            :fini="p.price_fini"
            @update:model-value="setFinish(i, $event)"
          />
        </div>
        <!-- The apartment's boxes: its linked ones + the unlinked pool to link. -->
        <div
          v-if="withBoxes && p.shortlistable_type === 'unit'"
          class="mt-2 border-t border-primary-200/60 pt-2 dark:border-primary-500/20"
        >
          <p class="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-mute">
            {{ $t('calls.boxesWithApartment') }}
          </p>
          <UnitBoxPicker
            :unit-id="p.shortlistable_id"
            :location-id="p.location_id"
            :model-value="p.box_ids ?? []"
            @update:model-value="setBoxIds(i, $event)"
          />
        </div>
      </div>
    </div>

    <div class="flex items-end gap-2">
      <!-- Project type narrows the PROJECT list (a location attribute). -->
      <BaseSelect
        v-model="projectTypeId"
        class="w-32 min-w-0 sm:w-40"
        :label="$t('inventory.projectType')"
        :placeholder="$t('common.all')"
        :options="projectTypes.map((t) => ({ value: t.id, label: itemLabel(t) }))"
      />
      <!-- min-w-0: without it the flex item takes the placeholder's intrinsic
           width and pushes the whole layout wider than a phone screen. -->
      <BaseSelect
        v-model="locationId"
        class="min-w-0 flex-1"
:label="$t('inventory.project')"
        :placeholder="$t('inventory.pickProject')"
        :options="locationOptions"
      />
      <button
        type="button"
        class="rounded-md border px-3 py-2.5 text-xs transition-colors hover:border-primary hover:text-ink"
        :class="hasUnitFilters ? 'border-primary text-ink' : 'border-line text-mute'"
        @click="showFilters = !showFilters"
      >
        <i class="pi pi-sliders-h text-[10px]" aria-hidden="true" />
        {{ $t('common.filters') }}
      </button>
    </div>

    <!-- The same refinements as Inventory → Units, compacted. Usable before a
         project is picked — they then narrow the project dropdown itself. -->
    <div v-if="showFilters" class="grid gap-3 rounded-xl border border-line p-3 sm:grid-cols-3">
      <BaseMultiSelect
        v-model="filters.room_number_id"
        :label="$t('inventory.rooms')"
        :options="roomNumbers.map((r) => ({ value: r.id, label: itemLabel(r) }))"
        @update:model-value="applyFilters()"
      />
      <BaseMultiSelect
        v-model="filters.floor_id"
:label="$t('inventory.floor')"
        :options="floors.map((f) => ({ value: f.id, label: itemLabel(f) }))"
        @update:model-value="applyFilters()"
      />
      <div class="grid grid-cols-2 gap-2">
        <MoneyInput
          v-model="filters.min_price"
:label="$t('inventory.minPrice')"
          @update:model-value="applyFiltersDebounced()"
        />
        <MoneyInput
          v-model="filters.max_price"
:label="$t('inventory.maxPrice')"
          @update:model-value="applyFiltersDebounced()"
        />
      </div>
      <div class="grid grid-cols-2 gap-2 sm:col-start-1">
        <BaseInput
          v-model="filters.min_area"
:label="$t('inventory.minArea')"
          type="number"
          @change="applyFilters()"
        />
        <BaseInput
          v-model="filters.max_area"
:label="$t('inventory.maxArea')"
          type="number"
          @change="applyFilters()"
        />
      </div>
      <p v-if="hasUnitFilters" class="text-xs text-mute sm:col-span-3">
        <i class="pi pi-info-circle text-[10px]" aria-hidden="true" />
        {{ $t('inventory.filtersNarrowProjects') }}
      </p>
    </div>

    <p v-if="loading" class="text-xs text-mute">{{ $t('inventory.loadingProperties') }}</p>
    <div v-else-if="locationId" class="flex flex-wrap gap-1.5">
      <button
        v-for="c in candidates"
        :key="c.type + c.id"
        type="button"
        class="rounded-full border px-3 py-1.5 text-xs transition-colors"
        :class="
          selectedKeys.has(`${c.type}:${c.id}`)
            ? 'border-primary bg-highlight font-medium text-ink'
            : 'border-line text-mute hover:border-primary hover:text-ink'
        "
        @click="toggle(c)"
      >
        <i
          :class="c.type === 'box' ? 'pi pi-car' : 'pi pi-home'"
          class="text-[10px]"
          aria-hidden="true"
        />
        {{ c.label }}
        <!-- Sale status, only when it matters (reserved / interested), with the
             live reservation count — "Reserved · 2" reads "you'd be 3rd". -->
        <span
          v-if="c.saleStatus"
          class="ms-1 inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-medium"
          :class="
            c.saleStatus === 'reserved'
              ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'
              : 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300'
          "
        >
          <i
            :class="c.saleStatus === 'reserved' ? 'pi pi-lock' : 'pi pi-thumbs-up'"
            class="text-[9px]"
            aria-hidden="true"
          />
          {{ $t(`status.${c.saleStatus}`) }}<template v-if="c.queueCount"> · {{ c.queueCount }}</template>
        </span>
      </button>
      <p v-if="!candidates.length" class="text-xs text-mute">
        {{ $t('inventory.noPropertiesMatch') }}
      </p>
    </div>
  </div>
</template>
