<script setup>
import { computed } from 'vue'
import Chart from 'primevue/chart'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import KpiTile from '@/features/analytics/components/kpi/KpiTile.vue'
import { useKpiSection } from '@/features/analytics/kpi/useKpiSection'
import { donut, rankedBar, chartTokens } from '@/features/analytics/kpi/charts'
import { formatMoney } from '@/features/payments/money'
import { t } from '@/i18n'

const props = defineProps({ params: { type: Object, required: true } })
const paramsRef = computed(() => props.params)
const { data, loading, error } = useKpiSection('inventory', paramsRef)

const statusChart = computed(() => {
  const s = data.value?.status_breakdown
  if (!s) return null
  const c = chartTokens()
  return donut(
    [t('status.available'), t('status.interested'), t('status.reserved'), t('status.sold')],
    [s.available, s.interested, s.reserved, s.sold],
    [c.mute, c.info, c.warning, c.success],
  )
})

const ladderChart = computed(() => {
  const rows = data.value?.price_ladder ?? []
  if (!rows.some((r) => r.count)) return null
  return rankedBar(rows.map((r) => r.bracket), rows.map((r) => r.count), 'primary')
})
</script>

<template>
  <div class="space-y-5">
    <SectionCard v-if="error"><EmptyState icon="pi pi-exclamation-triangle" :title="error" /></SectionCard>

    <template v-else>
      <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <KpiTile :label="t('kpi.totalUnits')" :value="data?.total_units" icon="pi pi-th-large" :loading="loading" />
        <KpiTile :label="t('status.sold')" :value="data?.sold_units" icon="pi pi-check-circle" tone="success" :loading="loading" />
        <KpiTile :label="t('kpi.sellThrough')" :value="data?.sell_through" suffix="%" icon="pi pi-percentage" :loading="loading" />
        <KpiTile :label="t('kpi.remainingValue')" :value="data?.remaining_value" money icon="pi pi-wallet" tone="info" :loading="loading" />
        <KpiTile :label="t('kpi.velocity')" :value="data?.velocity" :hint="t('kpi.perMonth')" icon="pi pi-forward" :loading="loading" />
        <KpiTile :label="t('kpi.monthsToSellout')" :value="data?.months_to_sellout ?? '—'" icon="pi pi-hourglass" tone="warning" :loading="loading" />
        <KpiTile :label="t('kpi.slowMovers')" :value="data?.slow_movers" :hint="t('kpi.over90Days')" icon="pi pi-clock" tone="danger" :loading="loading" />
        <KpiTile :label="t('kpi.remainingUnits')" :value="data?.remaining_units" icon="pi pi-inbox" :loading="loading" />
      </div>

      <div v-if="data?.sold_out_types?.length" class="flex flex-wrap items-center gap-2 rounded-xl border border-line bg-card px-4 py-3 shadow-card">
        <span class="text-sm font-medium text-mute">{{ t('kpi.soldOut') }}:</span>
        <span v-for="ty in data.sold_out_types" :key="ty" class="rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700 dark:bg-red-500/15 dark:text-red-300">{{ ty }}</span>
      </div>

      <div class="grid gap-5 lg:grid-cols-2">
        <SectionCard :title="t('kpi.statusBreakdown')" icon="pi pi-chart-pie">
          <div class="h-64"><Chart v-if="statusChart" type="doughnut" :data="statusChart.data" :options="statusChart.options" class="h-full" /></div>
        </SectionCard>
        <SectionCard :title="t('kpi.priceLadder')" icon="pi pi-sort-amount-up">
          <div v-if="ladderChart" class="h-64"><Chart type="bar" :data="ladderChart.data" :options="ladderChart.options" class="h-full" /></div>
          <EmptyState v-else icon="pi pi-sort-amount-up" :title="t('kpi.noStock')" />
        </SectionCard>
      </div>

      <SectionCard :title="t('kpi.byDevelopment')" icon="pi pi-building" flush>
        <DataTable :value="data?.by_location ?? []" :loading="loading" data-key="location_id" sort-mode="single">
          <template #empty><EmptyState icon="pi pi-building" :title="t('inventory.noUnitsTitle')" /></template>
          <Column :header="t('kpi.development')" field="location" sortable />
          <Column :header="t('kpi.totalUnits')" field="total" sortable class="text-end">
            <template #body="{ data: r }"><span class="num">{{ r.total }}</span></template>
          </Column>
          <Column :header="t('status.sold')" field="sold" sortable class="text-end">
            <template #body="{ data: r }"><span class="num">{{ r.sold }}</span></template>
          </Column>
          <Column :header="t('status.available')" field="available" sortable class="text-end">
            <template #body="{ data: r }"><span class="num">{{ r.available }}</span></template>
          </Column>
          <Column :header="t('kpi.sellThrough')" field="sell_through" sortable class="text-end">
            <template #body="{ data: r }"><span class="num">{{ r.sell_through }}%</span></template>
          </Column>
          <Column :header="t('kpi.remainingValue')" field="remaining_value" sortable class="text-end">
            <template #body="{ data: r }"><span class="num">{{ formatMoney(r.remaining_value) }}</span></template>
          </Column>
        </DataTable>
      </SectionCard>
    </template>
  </div>
</template>
