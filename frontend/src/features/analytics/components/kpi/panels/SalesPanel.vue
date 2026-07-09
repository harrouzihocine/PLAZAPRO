<script setup>
import { computed } from 'vue'
import Chart from 'primevue/chart'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import KpiTile from '@/features/analytics/components/kpi/KpiTile.vue'
import { useKpiSection } from '@/features/analytics/kpi/useKpiSection'
import { rankedBar, donut } from '@/features/analytics/kpi/charts'
import { formatMoney, dzdToMil } from '@/features/payments/money'
import { t } from '@/i18n'

const props = defineProps({ params: { type: Object, required: true } })
const paramsRef = computed(() => props.params)
const { data, loading, error } = useKpiSection('sales', paramsRef)

const typeLabel = (type) => (type === 'box' ? t('kpi.box') : type)

const mixChart = computed(() => {
  const m = data.value?.revenue_mix
  if (!m) return null
  const units = dzdToMil(m.units) ?? 0
  const boxes = dzdToMil(m.boxes) ?? 0
  if (units + boxes === 0) return null
  return donut([t('kpi.apartments'), t('kpi.boxes')], [units, boxes])
})

const byTypeChart = computed(() => {
  const rows = data.value?.by_type ?? []
  if (!rows.length) return null
  return rankedBar(
    rows.map((r) => typeLabel(r.type)),
    rows.map((r) => dzdToMil(r.value) ?? 0),
    'primary',
  )
})
</script>

<template>
  <div class="space-y-5">
    <SectionCard v-if="error"><EmptyState icon="pi pi-exclamation-triangle" :title="error" /></SectionCard>

    <template v-else>
      <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <KpiTile :label="t('kpi.salesValue')" :value="data?.sales_value?.value" money :delta="data?.sales_value?.delta" icon="pi pi-dollar" tone="success" :loading="loading" />
        <KpiTile :label="t('kpi.unitsSold')" :value="data?.units_sold?.total" :delta="data?.units_sold?.delta" icon="pi pi-home" :hint="data?.units_sold?.boxes ? t('kpi.plusBoxes', { n: data.units_sold.boxes }) : null" :loading="loading" />
        <KpiTile :label="t('kpi.avgSellingPrice')" :value="data?.avg_selling_price" money icon="pi pi-tag" :loading="loading" />
        <KpiTile :label="t('kpi.avgPriceSqm')" :value="data?.avg_price_sqm ?? '—'" money icon="pi pi-arrows-alt" :loading="loading" />
        <KpiTile :label="t('kpi.discountRate')" :value="data?.discount_rate" suffix="%" icon="pi pi-percentage" tone="warning" :loading="loading" />
        <KpiTile :label="t('kpi.salesVelocity')" :value="data?.sales_velocity" :hint="t('kpi.perMonth')" icon="pi pi-forward" :loading="loading" />
        <KpiTile :label="t('kpi.dealMomentum')" :value="data?.deal_momentum_days ?? '—'" :suffix="data?.deal_momentum_days != null ? ' ' + t('kpi.days') : ''" icon="pi pi-clock" tone="info" :loading="loading" />
        <KpiTile :label="t('kpi.netSales')" :value="data?.net_sales_value?.value" money icon="pi pi-chart-line" :loading="loading" />
      </div>

      <div class="grid gap-5 lg:grid-cols-2">
        <SectionCard :title="t('kpi.revenueMix')" icon="pi pi-chart-pie">
          <div v-if="mixChart" class="h-64"><Chart type="doughnut" :data="mixChart.data" :options="mixChart.options" class="h-full" /></div>
          <EmptyState v-else icon="pi pi-chart-pie" :title="t('kpi.noSales')" />
        </SectionCard>
        <SectionCard :title="t('kpi.revenueByType')" icon="pi pi-th-large">
          <div v-if="byTypeChart" class="h-64"><Chart type="bar" :data="byTypeChart.data" :options="byTypeChart.options" class="h-full" /></div>
          <EmptyState v-else icon="pi pi-th-large" :title="t('kpi.noSales')" />
        </SectionCard>
      </div>

      <SectionCard :title="t('kpi.revenueByDevelopment')" icon="pi pi-building" flush>
        <DataTable :value="data?.revenue_by_location ?? []" :loading="loading" data-key="location_id" sort-mode="single">
          <template #empty><EmptyState icon="pi pi-building" :title="t('kpi.noSales')" /></template>
          <Column :header="t('kpi.development')" field="location" sortable />
          <Column :header="t('kpi.unitsSold')" field="units" sortable class="text-end">
            <template #body="{ data: r }"><span class="num">{{ r.units }}</span></template>
          </Column>
          <Column :header="t('kpi.revenue')" field="value" sortable class="text-end">
            <template #body="{ data: r }"><span class="num font-medium text-ink">{{ formatMoney(r.value) }}</span></template>
          </Column>
        </DataTable>
      </SectionCard>
    </template>
  </div>
</template>
