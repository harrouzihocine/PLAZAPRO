<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseMultiSelect from '@/components/base/BaseMultiSelect.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import MoneyInput from '@/components/base/MoneyInput.vue'
import { useDynamicList } from '@/composables/useDynamicList'
import { boxesApi, locationsApi, unitsApi } from '@/features/inventory/api'
import { formatMoney } from '@/features/payments/money'
import UnitBoxPicker from '@/features/inventory/components/UnitBoxPicker.vue'

// The property picker used across the workflow (call log, office-visit shortlist,
// deal creation): pick a project (location), refine with the same filters as the
// inventory units screen (type / floor / price / area), then tap available units
// (and boxes, unless units-only) to multi-select them. Each candidate shows the
// FULL property card (reference · type · floor · area · price), not just its code.
//
// v-model is a list of { shortlistable_type: 'unit'|'box', shortlistable_id,
// label, location_id, box_ids? }. `exclude` hides properties already elsewhere
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

const locations = ref([])
const locationId = ref('')
const units = ref([])
const boxes = ref([])
const loading = ref(false)
const showFilters = ref(false)

// Inventory-style refinements, applied server-side like UnitsView.
const filters = reactive({
  floor_id: [],
  min_price: '',
  max_price: '',
  min_area: '',
  max_area: '',
})

onMounted(async () => {
  locations.value = await locationsApi.list()
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

// The Mil-scaled price fields emit on every keystroke, so debounce their reload.
let priceTimer = null
function loadCandidatesDebounced() {
  clearTimeout(priceTimer)
  priceTimer = setTimeout(loadCandidates, 400)
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

// One rich, human-readable card per property — all its info, not only the code.
function unitLabel(u) {
  return [
    u.reference,
    u.floor,
    u.area_sqm ? `${u.area_sqm} m²` : null,
    u.price ? formatMoney(u.price) : null,
  ]
    .filter(Boolean)
    .join(' · ')
}
function boxLabel(b) {
  return [b.reference, b.type, b.price ? formatMoney(b.price) : null].filter(Boolean).join(' · ')
}

const candidates = computed(() => {
  const all = [
    ...units.value.map((u) => ({
      type: 'unit',
      id: u.id,
      label: unitLabel(u),
      locationId: u.location_id,
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
            aria-label="Remove property"
            @click="remove(i)"
          >
            <i class="pi pi-times text-[10px]" aria-hidden="true" />
          </button>
        </span>
        <!-- The apartment's boxes: its linked ones + the unlinked pool to link. -->
        <div
          v-if="withBoxes && p.shortlistable_type === 'unit'"
          class="mt-2 border-t border-primary-200/60 pt-2 dark:border-primary-500/20"
        >
          <p class="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-mute">
            Boxes with this apartment
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
      <!-- min-w-0: without it the flex item takes the placeholder's intrinsic
           width and pushes the whole layout wider than a phone screen. -->
      <BaseSelect
        v-model="locationId"
        class="min-w-0 flex-1"
        label="Project"
        placeholder="Pick a project"
        :options="locations.map((l) => ({ value: l.id, label: `${l.code} · ${l.name}` }))"
      />
      <button
        v-if="locationId"
        type="button"
        class="rounded-md border border-line px-3 py-2.5 text-xs text-mute transition-colors hover:border-primary hover:text-ink"
        @click="showFilters = !showFilters"
      >
        <i class="pi pi-sliders-h text-[10px]" aria-hidden="true" />
        Filters
      </button>
    </div>

    <!-- The same refinements as Inventory → Units, compacted. -->
    <div
      v-if="showFilters && locationId"
      class="grid gap-3 rounded-xl border border-line p-3 sm:grid-cols-3"
    >
      <BaseMultiSelect
        v-model="filters.floor_id"
        label="Floor"
        :options="floors.map((f) => ({ value: f.id, label: f.label }))"
        @update:model-value="loadCandidates()"
      />
      <div class="grid grid-cols-2 gap-2">
        <MoneyInput
          v-model="filters.min_price"
          label="Min price"
          @update:model-value="loadCandidatesDebounced()"
        />
        <MoneyInput
          v-model="filters.max_price"
          label="Max price"
          @update:model-value="loadCandidatesDebounced()"
        />
      </div>
      <div class="grid grid-cols-2 gap-2 sm:col-start-1">
        <BaseInput
          v-model="filters.min_area"
          label="Min m²"
          type="number"
          @change="loadCandidates()"
        />
        <BaseInput
          v-model="filters.max_area"
          label="Max m²"
          type="number"
          @change="loadCandidates()"
        />
      </div>
    </div>

    <p v-if="loading" class="text-xs text-mute">Loading available properties…</p>
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
      </button>
      <p v-if="!candidates.length" class="text-xs text-mute">
        No available properties match in this project.
      </p>
    </div>
  </div>
</template>
