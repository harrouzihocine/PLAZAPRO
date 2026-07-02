<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseMultiSelect from '@/components/base/BaseMultiSelect.vue'
import { useDynamicList } from '@/composables/useDynamicList'
import SaleStatusBadge from '@/features/inventory/components/SaleStatusBadge.vue'
import { useLocationsStore } from '@/features/inventory/locationsStore'
import { useUnitsStore } from '@/features/inventory/unitsStore'

const units = useUnitsStore()
const locations = useLocationsStore()
const { items: unitTypes } = useDynamicList('unit_types')
const { items: floors } = useDynamicList('floors')
const { items: areas } = useDynamicList('areas')

// Map location id -> name so the table shows the project, not a raw FK.
const locationName = computed(() =>
  Object.fromEntries(locations.items.map((l) => [l.id, l.name])),
)

// { value, label } option lists for the multi-select filters.
const typeOptions = computed(() => unitTypes.value.map((t) => ({ value: t.id, label: t.label })))
const floorOptions = computed(() => floors.value.map((f) => ({ value: f.id, label: f.label })))
const areaOptions = computed(() => areas.value.map((a) => ({ value: a.id, label: a.label })))
const statusOptions = [
  { value: 'available', label: 'available' },
  { value: 'reserved', label: 'reserved' },
  { value: 'sold', label: 'sold' },
]

const showAdvanced = ref(false)

onMounted(async () => {
  if (!locations.items.length) await locations.fetch()
  await units.fetch()
})

function reset() {
  units.filters = {
    location_id: '',
    area_id: [],
    type_id: [],
    floor_id: [],
    sale_status: [],
    min_area: '',
    max_area: '',
    min_price: '',
    max_price: '',
  }
  units.fetch()
}
</script>

<template>
  <div class="space-y-4">
    <div>
      <h1 class="text-xl font-semibold">Units</h1>
      <p class="opacity-70">All apartments and lots across projects.</p>
    </div>

    <BaseCard>
      <div class="flex flex-nowrap items-end gap-3 overflow-x-auto">
        <BaseMultiSelect
          v-model="units.filters.area_id"
          label="Area"
          class="w-40 shrink-0"
          :options="areaOptions"
          @update:model-value="units.fetch()"
        />
        <label class="block w-40 shrink-0">
          <span class="mb-1 block text-sm">Project</span>
          <select v-model="units.filters.location_id" class="w-full rounded-token border border-border bg-bg px-3 py-2 min-h-[44px] text-ink" @change="units.fetch()">
            <option value="">All</option>
            <option v-for="l in locations.items" :key="l.id" :value="l.id">{{ l.name }}</option>
          </select>
        </label>
        <BaseMultiSelect
          v-model="units.filters.type_id"
          label="Type"
          class="w-40 shrink-0"
          :options="typeOptions"
          @update:model-value="units.fetch()"
        />
        <BaseMultiSelect
          v-model="units.filters.floor_id"
          label="Floor"
          class="w-40 shrink-0"
          :options="floorOptions"
          @update:model-value="units.fetch()"
        />
        <BaseMultiSelect
          v-model="units.filters.sale_status"
          label="Status"
          class="w-40 shrink-0"
          :options="statusOptions"
          @update:model-value="units.fetch()"
        />
        <BaseButton variant="ghost" class="shrink-0" @click="showAdvanced = !showAdvanced">
          Advanced search {{ showAdvanced ? '▴' : '▾' }}
        </BaseButton>
        <BaseButton class="shrink-0" @click="units.fetch()">Filter</BaseButton>
        <BaseButton variant="ghost" class="shrink-0" @click="reset">Reset</BaseButton>
      </div>

      <div v-if="showAdvanced" class="mt-3 grid gap-3 border-t border-border pt-3 sm:grid-cols-2 lg:grid-cols-4">
        <BaseInput v-model="units.filters.min_area" label="Min area (m²)" type="number" @keyup.enter="units.fetch()" />
        <BaseInput v-model="units.filters.max_area" label="Max area (m²)" type="number" @keyup.enter="units.fetch()" />
        <BaseInput v-model="units.filters.min_price" label="Min price" type="number" @keyup.enter="units.fetch()" />
        <BaseInput v-model="units.filters.max_price" label="Max price" type="number" @keyup.enter="units.fetch()" />
      </div>
    </BaseCard>

    <BaseCard>
      <p v-if="units.loading" class="py-4 text-center text-sm opacity-60">Loading…</p>
      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-left opacity-60">
            <tr>
              <th class="py-2 pr-3">Ref</th>
              <th class="py-2 pr-3">Project</th>
              <th class="py-2 pr-3">Area</th>
              <th class="py-2 pr-3">Type</th>
              <th class="py-2 pr-3">Floor</th>
              <th class="py-2 pr-3">Area (m²)</th>
              <th class="py-2 pr-3">Price</th>
              <th class="py-2 pr-3">Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in units.items" :key="u.id" class="border-t border-border">
              <td class="py-2 pr-3 font-medium">
                <RouterLink :to="{ name: 'inventory.unit', params: { id: u.id } }" class="hover:text-primary">
                  {{ u.reference }}
                </RouterLink>
              </td>
              <td class="py-2 pr-3">
                <RouterLink :to="{ name: 'inventory.location', params: { id: u.location_id } }" class="hover:text-primary">
                  {{ locationName[u.location_id] ?? u.location_id }}
                </RouterLink>
              </td>
              <td class="py-2 pr-3">{{ u.location?.area || '—' }}</td>
              <td class="py-2 pr-3">{{ u.type || '—' }}</td>
              <td class="py-2 pr-3">{{ u.floor || '—' }}</td>
              <td class="py-2 pr-3">{{ u.area_sqm ?? '—' }}</td>
              <td class="py-2 pr-3">{{ u.price }}</td>
              <td class="py-2 pr-3"><SaleStatusBadge :status="u.sale_status" /></td>
            </tr>
            <tr v-if="!units.items.length">
              <td colspan="8" class="py-4 text-center text-sm opacity-60">No units match.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </BaseCard>
  </div>
</template>
