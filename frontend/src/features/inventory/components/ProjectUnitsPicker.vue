<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseMultiSelect from '@/components/base/BaseMultiSelect.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import { useDynamicList } from '@/composables/useDynamicList'
import { boxesApi, locationsApi, unitsApi } from '@/features/inventory/api'

// The property picker used across the workflow (call log, office-visit shortlist,
// deal creation): pick a project (location), refine with the same filters as the
// inventory units screen (type / floor / price / area), then tap available units
// and boxes to multi-select them. Each candidate shows the FULL property card
// (reference · type · floor · area · price), not just its code.
//
// v-model is a list of { shortlistable_type: 'unit'|'box', shortlistable_id,
// label, location_id, box_count? }. `exclude` hides properties already elsewhere
// (e.g. on the saved shortlist). With `with-boxes`, every selected unit offers the
// "include boxes" decision: a checkbox plus how many (1 → max available, not taken).
const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  exclude: { type: Array, default: () => [] }, // ['unit:12', 'box:3', …]
  withBoxes: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const { items: unitTypes } = useDynamicList('unit_types')
const { items: floors } = useDynamicList('floors')

const locations = ref([])
const locationId = ref('')
const units = ref([])
const boxes = ref([])
const loading = ref(false)
const showFilters = ref(false)

// Inventory-style refinements, applied server-side like UnitsView.
const filters = reactive({ type_id: [], floor_id: [], min_price: '', max_price: '', min_area: '', max_area: '' })

// Available (not taken) boxes per location — drives the box_count ceiling.
const availableBoxCount = ref({})

onMounted(async () => {
  locations.value = await locationsApi.list()
})

async function loadCandidates() {
  if (!locationId.value) return
  loading.value = true
  try {
    const params = { location_id: locationId.value, sale_status: 'available' }
    for (const [k, v] of Object.entries(filters)) {
      if (Array.isArray(v) ? v.length : v !== '') params[k] = v
    }
    const [u, b] = await Promise.all([
      unitsApi.list(params),
      boxesApi.list({ location_id: locationId.value, sale_status: 'available' }),
    ])
    units.value = u
    boxes.value = b
    availableBoxCount.value = { ...availableBoxCount.value, [locationId.value]: b.length }
  } finally {
    loading.value = false
  }
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
  return [u.reference, u.type, u.floor, u.area_sqm ? `${u.area_sqm} m²` : null, u.price]
    .filter(Boolean)
    .join(' · ')
}
function boxLabel(b) {
  return [b.reference, b.type, b.price].filter(Boolean).join(' · ')
}

const candidates = computed(() => {
  const all = [
    ...units.value.map((u) => ({ type: 'unit', id: u.id, label: unitLabel(u), locationId: u.location_id })),
    ...boxes.value.map((b) => ({ type: 'box', id: b.id, label: boxLabel(b), locationId: b.location_id })),
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
        ...(props.withBoxes && candidate.type === 'unit' ? { box_count: 0 } : {}),
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

const maxBoxesFor = (p) => availableBoxCount.value[p.location_id] ?? 0

function setBoxCount(index, count) {
  const next = props.modelValue.map((p, i) => (i === index ? { ...p, box_count: count } : p))
  emit('update:modelValue', next)
}

function toggleBoxes(index, checked) {
  setBoxCount(index, checked ? 1 : 0)
}
</script>

<template>
  <div class="space-y-2">
    <!-- Already-selected properties — full card + the box decision per unit. -->
    <div v-if="modelValue.length" class="space-y-1.5">
      <div
        v-for="(p, i) in modelValue"
        :key="p.shortlistable_type + p.shortlistable_id"
        class="rounded-token bg-primary/10 px-2 py-1.5 text-xs"
      >
        <span class="inline-flex w-full items-center gap-1">
          <span class="flex-1">
            {{ p.shortlistable_type === 'box' ? '🅿' : '🏠' }}
            {{ p.label ?? `${p.shortlistable_type} #${p.shortlistable_id}` }}
          </span>
          <button type="button" class="opacity-60 hover:text-danger" @click="remove(i)">✕</button>
        </span>
        <!-- Include boxes with this apartment (1 → max available, not taken). -->
        <div
          v-if="withBoxes && p.shortlistable_type === 'unit' && maxBoxesFor(p) > 0"
          class="mt-1 flex items-center gap-2 border-t border-primary/20 pt-1"
        >
          <label class="inline-flex items-center gap-1.5">
            <input
              type="checkbox"
              :checked="(p.box_count ?? 0) > 0"
              @change="toggleBoxes(i, $event.target.checked)"
            />
            <span>Include box(es)</span>
          </label>
          <template v-if="(p.box_count ?? 0) > 0">
            <select
              class="rounded-token border border-border bg-bg px-1.5 py-0.5 text-xs text-ink"
              :value="p.box_count"
              @change="setBoxCount(i, Number($event.target.value))"
            >
              <option v-for="n in maxBoxesFor(p)" :key="n" :value="n">{{ n }}</option>
            </select>
            <span class="opacity-60">of {{ maxBoxesFor(p) }} available</span>
          </template>
        </div>
      </div>
    </div>

    <div class="flex items-end gap-2">
      <BaseSelect
        v-model="locationId"
        class="flex-1"
        label="Project"
        placeholder="Pick a project to browse its available properties"
        :options="locations.map((l) => ({ value: l.id, label: `${l.code} · ${l.name}` }))"
      />
      <button
        v-if="locationId"
        type="button"
        class="rounded-token border border-border px-2 py-2 text-xs opacity-80 hover:border-primary"
        @click="showFilters = !showFilters"
      >
        Filters {{ showFilters ? '▴' : '▾' }}
      </button>
    </div>

    <!-- The same refinements as Inventory → Units, compacted. -->
    <div v-if="showFilters && locationId" class="grid gap-2 rounded-token border border-border p-2 sm:grid-cols-3">
      <BaseMultiSelect
        v-model="filters.type_id"
        label="Type"
        :options="unitTypes.map((t) => ({ value: t.id, label: t.label }))"
        @update:model-value="loadCandidates()"
      />
      <BaseMultiSelect
        v-model="filters.floor_id"
        label="Floor"
        :options="floors.map((f) => ({ value: f.id, label: f.label }))"
        @update:model-value="loadCandidates()"
      />
      <div class="grid grid-cols-2 gap-2">
        <BaseInput v-model="filters.min_price" label="Min price" type="number" @change="loadCandidates()" />
        <BaseInput v-model="filters.max_price" label="Max price" type="number" @change="loadCandidates()" />
      </div>
      <div class="grid grid-cols-2 gap-2 sm:col-start-1">
        <BaseInput v-model="filters.min_area" label="Min m²" type="number" @change="loadCandidates()" />
        <BaseInput v-model="filters.max_area" label="Max m²" type="number" @change="loadCandidates()" />
      </div>
    </div>

    <p v-if="loading" class="text-xs opacity-60">Loading available properties…</p>
    <div v-else-if="locationId" class="flex flex-wrap gap-1.5">
      <button
        v-for="c in candidates"
        :key="c.type + c.id"
        type="button"
        class="rounded-token border px-2 py-1 text-xs transition-colors"
        :class="
          selectedKeys.has(`${c.type}:${c.id}`)
            ? 'border-primary bg-primary/15 text-ink'
            : 'border-border bg-bg opacity-80 hover:border-primary'
        "
        @click="toggle(c)"
      >
        {{ c.type === 'box' ? '🅿 ' : '🏠 ' }}{{ c.label }}
      </button>
      <p v-if="!candidates.length" class="text-xs opacity-60">
        No available properties match in this project.
      </p>
    </div>
  </div>
</template>
