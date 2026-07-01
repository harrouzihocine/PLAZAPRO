<script setup>
import { computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import { useDynamicList } from '@/composables/useDynamicList'
import SaleStatusBadge from '@/features/inventory/components/SaleStatusBadge.vue'
import { useLocationsStore } from '@/features/inventory/locationsStore'
import { useUnitsStore } from '@/features/inventory/unitsStore'

const units = useUnitsStore()
const locations = useLocationsStore()
const { items: unitTypes } = useDynamicList('unit_types')

// Map location id -> name so the table shows the project, not a raw FK.
const locationName = computed(() =>
  Object.fromEntries(locations.items.map((l) => [l.id, l.name])),
)

onMounted(async () => {
  if (!locations.items.length) await locations.fetch()
  await units.fetch()
})

function reset() {
  units.filters = {
    location_id: '',
    type_id: '',
    floor_id: '',
    sale_status: '',
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
      <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <label class="block">
          <span class="mb-1 block text-sm">Project</span>
          <select v-model="units.filters.location_id" class="w-full rounded-token border border-border bg-bg px-3 py-2 min-h-[44px] text-ink" @change="units.fetch()">
            <option value="">All</option>
            <option v-for="l in locations.items" :key="l.id" :value="l.id">{{ l.name }}</option>
          </select>
        </label>
        <label class="block">
          <span class="mb-1 block text-sm">Type</span>
          <select v-model="units.filters.type_id" class="w-full rounded-token border border-border bg-bg px-3 py-2 min-h-[44px] text-ink" @change="units.fetch()">
            <option value="">All</option>
            <option v-for="t in unitTypes" :key="t.id" :value="t.id">{{ t.label }}</option>
          </select>
        </label>
        <label class="block">
          <span class="mb-1 block text-sm">Status</span>
          <select v-model="units.filters.sale_status" class="w-full rounded-token border border-border bg-bg px-3 py-2 min-h-[44px] text-ink" @change="units.fetch()">
            <option value="">All</option>
            <option value="available">available</option>
            <option value="reserved">reserved</option>
            <option value="sold">sold</option>
          </select>
        </label>
        <BaseInput v-model="units.filters.min_price" label="Min price" type="number" @keyup.enter="units.fetch()" />
        <BaseInput v-model="units.filters.max_price" label="Max price" type="number" @keyup.enter="units.fetch()" />
        <div class="flex items-end gap-2">
          <BaseButton @click="units.fetch()">Filter</BaseButton>
          <BaseButton variant="ghost" @click="reset">Reset</BaseButton>
        </div>
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
              <th class="py-2 pr-3">Type</th>
              <th class="py-2 pr-3">Price</th>
              <th class="py-2 pr-3">Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in units.items" :key="u.id" class="border-t border-border">
              <td class="py-2 pr-3 font-medium">
                <RouterLink :to="{ name: 'inventory.location', params: { id: u.location_id } }" class="hover:text-primary">
                  {{ u.reference }}
                </RouterLink>
              </td>
              <td class="py-2 pr-3">{{ locationName[u.location_id] ?? u.location_id }}</td>
              <td class="py-2 pr-3">{{ u.type || '—' }}</td>
              <td class="py-2 pr-3">{{ u.price }}</td>
              <td class="py-2 pr-3"><SaleStatusBadge :status="u.sale_status" /></td>
            </tr>
            <tr v-if="!units.items.length">
              <td colspan="5" class="py-4 text-center text-sm opacity-60">No units match.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </BaseCard>
  </div>
</template>
