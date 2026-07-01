<script setup>
import { onMounted, ref } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import { analyticsApi } from '@/features/analytics/api'
import { formatMoney } from '@/features/payments/money'
import SaleStatusBadge from '@/features/inventory/components/SaleStatusBadge.vue'

const tab = ref('roi') // roi | units

// --- Source ROI ---
const roi = ref([])
const roiLoading = ref(false)
const range = ref({ from: '', to: '' })

async function loadRoi() {
  roiLoading.value = true
  try {
    const params = {}
    if (range.value.from) params.from = range.value.from
    if (range.value.to) params.to = range.value.to
    roi.value = await analyticsApi.sourceRoi(params)
  } finally {
    roiLoading.value = false
  }
}

// --- Unit intelligence ---
const units = ref([])
const unitsLoading = ref(false)

async function loadUnits() {
  unitsLoading.value = true
  try {
    units.value = await analyticsApi.units()
  } finally {
    unitsLoading.value = false
  }
}

function show(next) {
  tab.value = next
  if (next === 'units' && !units.value.length) loadUnits()
}

onMounted(loadRoi)
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-2xl font-semibold">Reports</h1>

    <div class="flex gap-2">
      <BaseButton :variant="tab === 'roi' ? 'primary' : 'ghost'" @click="show('roi')">
        Source ROI
      </BaseButton>
      <BaseButton :variant="tab === 'units' ? 'primary' : 'ghost'" @click="show('units')">
        Unit intelligence
      </BaseButton>
    </div>

    <!-- Source ROI -->
    <BaseCard v-show="tab === 'roi'">
      <div class="flex flex-wrap items-end gap-2">
        <BaseInput v-model="range.from" type="date" label="Leads from" />
        <BaseInput v-model="range.to" type="date" label="Leads to" />
        <BaseButton @click="loadRoi">Apply</BaseButton>
      </div>

      <p v-if="roiLoading" class="py-4 text-center text-sm opacity-60">Loading…</p>
      <p v-else-if="!roi.length" class="py-4 text-center text-sm opacity-60">No leads in this range.</p>

      <div v-else class="mt-3 overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-left opacity-60">
            <tr>
              <th class="py-2 pr-3">Source</th>
              <th class="py-2 pr-3 text-right">Leads</th>
              <th class="py-2 pr-3 text-right">Visits</th>
              <th class="py-2 pr-3 text-right">Won</th>
              <th class="py-2 pr-3 text-right">Conversion</th>
              <th class="py-2 text-right">Revenue</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in roi" :key="r.source_id ?? 'none'" class="border-t border-border">
              <td class="py-2 pr-3 font-medium">{{ r.source }}</td>
              <td class="py-2 pr-3 text-right">{{ r.leads }}</td>
              <td class="py-2 pr-3 text-right">{{ r.visits }}</td>
              <td class="py-2 pr-3 text-right">{{ r.won }}</td>
              <td class="py-2 pr-3 text-right">{{ r.conversion }}%</td>
              <td class="py-2 text-right">{{ formatMoney(r.revenue) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </BaseCard>

    <!-- Unit intelligence -->
    <BaseCard v-show="tab === 'units'">
      <p v-if="unitsLoading" class="py-4 text-center text-sm opacity-60">Loading…</p>
      <p v-else-if="!units.length" class="py-4 text-center text-sm opacity-60">No units yet.</p>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-left opacity-60">
            <tr>
              <th class="py-2 pr-3">Unit</th>
              <th class="py-2 pr-3">Location</th>
              <th class="py-2 pr-3">Status</th>
              <th class="py-2 pr-3 text-right">Visits</th>
              <th class="py-2 pr-3 text-right">Holds</th>
              <th class="py-2 pr-3 text-right">Won</th>
              <th class="py-2 pr-3 text-right">Conversion</th>
              <th class="py-2 text-right">Price</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in units" :key="u.id" class="border-t border-border">
              <td class="py-2 pr-3 font-medium">{{ u.reference }}</td>
              <td class="py-2 pr-3">{{ u.location ?? '—' }}</td>
              <td class="py-2 pr-3"><SaleStatusBadge :status="u.sale_status" /></td>
              <td class="py-2 pr-3 text-right">{{ u.visits }}</td>
              <td class="py-2 pr-3 text-right">{{ u.holds }}</td>
              <td class="py-2 pr-3 text-right">{{ u.won }}</td>
              <td class="py-2 pr-3 text-right">{{ u.conversion }}%</td>
              <td class="py-2 text-right">{{ formatMoney(u.price) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </BaseCard>
  </div>
</template>
