<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseMultiSelect from '@/components/base/BaseMultiSelect.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useAutoFilter } from '@/composables/useAutoFilter'
import { useDynamicList } from '@/composables/useDynamicList'
import { useWilayas, useCommunes } from '@/composables/useGeography'
import { GTM_PRIORITIES } from '@/features/inventory/api'
import GtmPriorityBadge from '@/features/inventory/components/GtmPriorityBadge.vue'
import SaleStatusBadge from '@/features/inventory/components/SaleStatusBadge.vue'
import { useLocationsStore } from '@/features/inventory/locationsStore'
import { useUnitsStore } from '@/features/inventory/unitsStore'
import { formatMoney } from '@/features/payments/money'

const units = useUnitsStore()
const locations = useLocationsStore()
const router = useRouter()
const { items: unitTypes } = useDynamicList('unit_types')
const { items: floors } = useDynamicList('floors')
const { wilayas } = useWilayas()
const { load: loadCommunes } = useCommunes()

// Map location id -> name so the table shows the project, not a raw FK.
const locationName = computed(() => Object.fromEntries(locations.items.map((l) => [l.id, l.name])))

// { value, label } option lists for the multi-select filters.
const typeOptions = computed(() => unitTypes.value.map((t) => ({ value: t.id, label: t.label })))
const floorOptions = computed(() => floors.value.map((f) => ({ value: f.id, label: f.label })))
const wilayaOptions = computed(() =>
  wilayas.value.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` })),
)
const projectOptions = computed(() => locations.items.map((l) => ({ value: l.id, label: l.name })))

// Communes cascade from the selected wilaya(s): their union, deduped by id.
const communeItems = ref([])
const communeOptions = computed(() =>
  communeItems.value.map((c) => ({ value: c.id, label: c.name })),
)

async function refreshCommuneOptions() {
  const ids = units.filters.wilaya_id
  if (!ids.length) {
    communeItems.value = []
    return
  }
  const lists = await Promise.all(ids.map((id) => loadCommunes(id)))
  const merged = new Map()
  for (const list of lists) for (const c of list) merged.set(c.id, c)
  communeItems.value = [...merged.values()]
}

// When the wilaya selection changes, refresh commune choices and drop any
// selected commune whose wilaya is no longer chosen — the auto-filter watcher
// below reloads the table.
watch(
  () => units.filters.wilaya_id,
  async () => {
    await refreshCommuneOptions()
    const valid = new Set(communeItems.value.map((c) => c.id))
    units.filters.commune_id = units.filters.commune_id.filter((id) => valid.has(id))
  },
)

// Filters apply themselves as they change — no "Filter" button.
useAutoFilter(() => units.filters, () => units.fetch())
const statusOptions = [
  { value: 'available', label: 'Available' },
  { value: 'reserved', label: 'Reserved' },
  { value: 'sold', label: 'Sold' },
]
// GTM priority filter options (shared source of truth).
const priorityOptions = GTM_PRIORITIES

const showAdvanced = ref(false)

onMounted(async () => {
  if (!locations.items.length) await locations.fetch()
  await units.fetch()
})

function reset() {
  // The auto-filter watcher picks the change up and refetches.
  units.filters = {
    location_id: '',
    wilaya_id: [],
    commune_id: [],
    type_id: [],
    floor_id: [],
    sale_status: [],
    priority: [],
    min_area: '',
    max_area: '',
    min_price: '',
    max_price: '',
  }
}

function openUnit(event) {
  router.push({ name: 'inventory.unit', params: { id: event.data.id } })
}
</script>

<template>
  <div>
    <PageHeader title="Units" subtitle="All apartments and lots across projects." />

    <SectionCard flush class="mb-5">
      <div class="flex flex-wrap items-end gap-2 px-4 py-3 sm:px-5">
        <BaseSelect
          v-model="units.filters.location_id"
          placeholder="All projects"
          aria-label="Filter by project"
          class="w-full sm:w-44"
          :options="projectOptions"
        />
        <BaseMultiSelect
          v-model="units.filters.type_id"
          placeholder="Type"
          class="w-full sm:w-40"
          :options="typeOptions"
        />
        <BaseMultiSelect
          v-model="units.filters.floor_id"
          placeholder="Floor"
          class="w-full sm:w-36"
          :options="floorOptions"
        />
        <BaseMultiSelect
          v-model="units.filters.sale_status"
          placeholder="Status"
          class="w-full sm:w-36"
          :options="statusOptions"
        />
        <BaseMultiSelect
          v-model="units.filters.priority"
          placeholder="Priority"
          class="w-full sm:w-36"
          :options="priorityOptions"
        />
        <Button
          :label="showAdvanced ? 'Less filters' : 'More filters'"
          :icon="showAdvanced ? 'pi pi-chevron-up' : 'pi pi-sliders-h'"
          text
          size="small"
          severity="secondary"
          @click="showAdvanced = !showAdvanced"
        />
        <span class="ml-auto flex gap-2">
          <Button
            icon="pi pi-filter-slash"
            text
            severity="secondary"
            aria-label="Reset filters"
            @click="reset"
          />
        </span>
      </div>

      <div
        v-if="showAdvanced"
        class="grid gap-3 border-t border-line px-4 py-3 sm:grid-cols-2 sm:px-5 lg:grid-cols-4"
      >
        <BaseMultiSelect
          v-model="units.filters.wilaya_id"
          label="Wilaya"
          :options="wilayaOptions"
        />
        <BaseMultiSelect
          v-model="units.filters.commune_id"
          label="Commune"
          :options="communeOptions"
          placeholder="Select a wilaya first"
        />
        <BaseInput v-model="units.filters.min_area" label="Min area (m²)" type="number" />
        <BaseInput v-model="units.filters.max_area" label="Max area (m²)" type="number" />
        <BaseInput v-model="units.filters.min_price" label="Min price" type="number" />
        <BaseInput v-model="units.filters.max_price" label="Max price" type="number" />
      </div>
    </SectionCard>

    <SectionCard flush>
      <DataTable
        :value="units.items"
        :loading="units.loading"
        paginator
        :rows="25"
        :rows-per-page-options="[25, 50, 100]"
        data-key="id"
        class="cursor-pointer"
        @row-click="openUnit"
      >
        <template #empty>
          <EmptyState
            icon="pi pi-th-large"
            title="No units match"
            body="Loosen the filters to see more inventory."
          />
        </template>

        <Column header="Reference">
          <template #body="{ data }">
            <span class="font-medium text-ink">{{ data.reference }}</span>
          </template>
        </Column>
        <Column header="Wilaya">
          <template #body="{ data }">
            {{ data.location?.wilaya || '—' }}
            <span v-if="data.location?.commune" class="text-mute">
              · {{ data.location.commune }}</span
            >
          </template>
        </Column>
        <Column header="Project">
          <template #body="{ data }">
            <RouterLink
              :to="{ name: 'inventory.location', params: { id: data.location_id } }"
              class="text-ink hover:underline"
              @click.stop
            >
              {{ locationName[data.location_id] ?? data.location_id }}
            </RouterLink>
            <span v-if="data.location?.contract_type" class="block text-xs text-mute">
              {{ data.location.contract_type }}
            </span>
          </template>
        </Column>
        <Column header="Type">
          <template #body="{ data }">{{ data.type || '—' }}</template>
        </Column>
        <Column header="Floor">
          <template #body="{ data }">{{ data.floor || '—' }}</template>
        </Column>
        <Column header="Area">
          <template #body="{ data }">
            <span class="num">{{ data.area_sqm ? `${data.area_sqm} m²` : '—' }}</span>
          </template>
        </Column>
        <Column header="Price">
          <template #body="{ data }">
            <span class="num">{{ formatMoney(data.price) }}</span>
          </template>
        </Column>
        <Column header="Status">
          <template #body="{ data }"><SaleStatusBadge :status="data.sale_status" /></template>
        </Column>
        <Column header="Priority">
          <template #body="{ data }">
            <GtmPriorityBadge v-if="data.gtm_priority" :priority="data.gtm_priority" />
            <span v-else class="text-mute">—</span>
          </template>
        </Column>
      </DataTable>
    </SectionCard>
  </div>
</template>
