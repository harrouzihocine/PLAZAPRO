<script setup>
import { onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import BaseInput from '@/components/base/BaseInput.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import FilterPanel from '@/components/ui/FilterPanel.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { analyticsApi } from '@/features/analytics/api'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { formatMoney } from '@/features/payments/money'
import SaleStatusBadge from '@/features/inventory/components/SaleStatusBadge.vue'

const tab = ref('roi') // roi | units
const error = ref('')

// --- Source ROI ---
const roi = ref([])
const roiLoading = ref(false)
const range = ref({ from: '', to: '' })

async function loadRoi() {
  roiLoading.value = true
  error.value = ''
  try {
    const params = {}
    if (range.value.from) params.from = range.value.from
    if (range.value.to) params.to = range.value.to
    roi.value = await analyticsApi.sourceRoi(params)
  } catch {
    error.value = 'Could not load the report. Please try again.'
  } finally {
    roiLoading.value = false
  }
}

// --- Unit intelligence ---
const units = ref([])
const unitsLoading = ref(false)

async function loadUnits() {
  unitsLoading.value = true
  error.value = ''
  try {
    units.value = await analyticsApi.units()
  } catch {
    error.value = 'Could not load the report. Please try again.'
  } finally {
    unitsLoading.value = false
  }
}

function show(next) {
  tab.value = next
  if (next === 'units' && !units.value.length) loadUnits()
}

onMounted(loadRoi)
// Refresh whichever report tab is on screen (pull-to-refresh + reconnect self-heal).
useRefreshable(() => (tab.value === 'units' ? loadUnits() : loadRoi()))
</script>

<template>
  <div>
    <PageHeader
      title="Reports"
      subtitle="Where leads come from, what they convert into, and which units the market wants."
    />

    <nav class="mb-5 inline-flex rounded-lg border border-line bg-card p-1 shadow-card">
      <button
        type="button"
        class="flex items-center gap-2 rounded-md px-4 py-2 text-sm transition-colors"
        :class="tab === 'roi' ? 'bg-highlight font-semibold text-ink' : 'text-mute hover:text-ink'"
        @click="show('roi')"
      >
        <i class="pi pi-chart-line" aria-hidden="true" />
        Source ROI
      </button>
      <button
        type="button"
        class="flex items-center gap-2 rounded-md px-4 py-2 text-sm transition-colors"
        :class="
          tab === 'units' ? 'bg-highlight font-semibold text-ink' : 'text-mute hover:text-ink'
        "
        @click="show('units')"
      >
        <i class="pi pi-th-large" aria-hidden="true" />
        Unit intelligence
      </button>
    </nav>

    <SectionCard v-if="error" class="mb-5">
      <EmptyState icon="pi pi-exclamation-triangle" :title="error" />
    </SectionCard>

    <!-- Source ROI: which lead source actually sells (cohort by lead) -->
    <SectionCard v-show="tab === 'roi'" flush>
      <FilterPanel :active-count="(range.from ? 1 : 0) + (range.to ? 1 : 0)">
      <div class="flex flex-wrap items-end gap-2 border-b border-line px-4 py-3 sm:px-5">
        <BaseInput v-model="range.from" type="date" label="Leads from" />
        <BaseInput v-model="range.to" type="date" label="Leads to" />
        <Button label="Apply" icon="pi pi-refresh" @click="loadRoi" />
      </div>
      </FilterPanel>

      <DataTable :value="roi" :loading="roiLoading" data-key="source_id" sort-mode="single">
        <template #empty>
          <EmptyState icon="pi pi-chart-line" title="No leads in this range" />
        </template>
        <Column header="Source" field="source" sortable>
          <template #body="{ data }">
            <span class="font-medium text-ink">{{ data.source }}</span>
          </template>
        </Column>
        <Column header="Leads" field="leads" sortable class="text-end">
          <template #body="{ data }"
            ><span class="num">{{ data.leads }}</span></template
          >
        </Column>
        <Column header="Visits" field="visits" sortable class="text-end">
          <template #body="{ data }"
            ><span class="num">{{ data.visits }}</span></template
          >
        </Column>
        <Column header="Won" field="won" sortable class="text-end">
          <template #body="{ data }"
            ><span class="num">{{ data.won }}</span></template
          >
        </Column>
        <Column header="Conversion" field="conversion" sortable>
          <template #body="{ data }">
            <span class="flex items-center gap-2">
              <span
                class="relative h-1.5 w-20 overflow-hidden rounded bg-surface-100 dark:bg-surface-800"
              >
                <span
                  class="absolute inset-y-0 start-0 rounded bg-primary-600 dark:bg-primary-400"
                  :style="{ width: `${Math.min(100, data.conversion)}%` }"
                  aria-hidden="true"
                />
              </span>
              <span class="num text-sm">{{ data.conversion }}%</span>
            </span>
          </template>
        </Column>
        <Column header="Revenue" field="revenue" sortable class="text-end">
          <template #body="{ data }">
            <span class="num font-medium text-ink">{{ formatMoney(data.revenue) }}</span>
          </template>
        </Column>
      </DataTable>
    </SectionCard>

    <!-- Unit intelligence: demand signals per unit -->
    <SectionCard v-show="tab === 'units'" flush>
      <DataTable
        :value="units"
        :loading="unitsLoading"
        data-key="id"
        sort-mode="single"
        paginator
        :rows="25"
      >
        <template #empty>
          <EmptyState icon="pi pi-th-large" title="No units yet" />
        </template>
        <Column header="Unit" field="reference" sortable>
          <template #body="{ data }">
            <span class="font-medium text-ink">{{ data.reference }}</span>
          </template>
        </Column>
        <Column header="Location" field="location" sortable>
          <template #body="{ data }">{{ data.location ?? '—' }}</template>
        </Column>
        <Column header="Status">
          <template #body="{ data }"><SaleStatusBadge :status="data.sale_status" /></template>
        </Column>
        <Column header="Visits" field="visits" sortable class="text-end">
          <template #body="{ data }"
            ><span class="num">{{ data.visits }}</span></template
          >
        </Column>
        <Column header="Holds" field="holds" sortable class="text-end">
          <template #body="{ data }"
            ><span class="num">{{ data.holds }}</span></template
          >
        </Column>
        <Column header="Won" field="won" sortable class="text-end">
          <template #body="{ data }"
            ><span class="num">{{ data.won }}</span></template
          >
        </Column>
        <Column header="Conversion" field="conversion" sortable class="text-end">
          <template #body="{ data }"
            ><span class="num">{{ data.conversion }}%</span></template
          >
        </Column>
        <Column header="Price" field="price" sortable class="text-end">
          <template #body="{ data }">
            <span class="num">{{ formatMoney(data.price) }}</span>
          </template>
        </Column>
      </DataTable>
    </SectionCard>
  </div>
</template>
