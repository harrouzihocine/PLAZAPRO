<script setup>
import { computed } from 'vue'
import Chart from 'primevue/chart'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import KpiTile from '@/features/analytics/components/kpi/KpiTile.vue'
import { useKpiSection } from '@/features/analytics/kpi/useKpiSection'
import { chartTokens } from '@/features/analytics/kpi/charts'
import { formatMoney, dzdToMil } from '@/features/payments/money'
import { t } from '@/i18n'

const props = defineProps({ params: { type: Object, required: true } })
const paramsRef = computed(() => props.params)
const { data, loading, error } = useKpiSection('collections', paramsRef)

const agingChart = computed(() => {
  const rows = data.value?.aging ?? []
  if (!rows.some((r) => Number(r.amount) > 0)) return null
  const c = chartTokens()
  return {
    data: {
      labels: rows.map((r) => r.bucket),
      datasets: [
        {
          data: rows.map((r) => dzdToMil(r.amount) ?? 0),
          backgroundColor: [c.warning, '#ea9a3e', c.danger, '#991b1b'],
          borderRadius: 4,
          borderSkipped: false,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { ticks: { color: c.ink }, grid: { display: false } },
        y: { beginAtZero: true, ticks: { color: c.mute }, grid: { color: c.line } },
      },
    },
  }
})
</script>

<template>
  <div class="space-y-5">
    <SectionCard v-if="error"><EmptyState icon="pi pi-exclamation-triangle" :title="error" /></SectionCard>

    <template v-else>
      <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <KpiTile :label="t('kpi.collected')" :value="data?.collected?.value" money :delta="data?.collected?.delta" icon="pi pi-wallet" tone="success" :loading="loading" />
        <KpiTile :label="t('kpi.outstanding')" :value="data?.outstanding" money icon="pi pi-hourglass" tone="warning" :loading="loading" />
        <KpiTile :label="t('kpi.overdueAmount')" :value="data?.overdue_amount" money icon="pi pi-exclamation-circle" tone="danger" :loading="loading" />
        <KpiTile :label="t('kpi.overdueCount')" :value="data?.overdue_count" :hint="t('kpi.clientsN', { n: data?.overdue_clients ?? 0 })" icon="pi pi-list" tone="danger" :loading="loading" />
        <KpiTile :label="t('kpi.collectionRate')" :value="data?.collection_rate" suffix="%" icon="pi pi-percentage" tone="info" :loading="loading" />
        <KpiTile :label="t('kpi.pctContractCollected')" :value="data?.pct_contract_collected" suffix="%" icon="pi pi-chart-line" :loading="loading" />
        <KpiTile :label="t('kpi.planCompliance')" :value="data?.plan_compliance" suffix="%" icon="pi pi-verified" tone="success" :loading="loading" />
        <KpiTile :label="t('kpi.avgDownPayment')" :value="data?.avg_down_payment_pct" suffix="%" icon="pi pi-percentage" :loading="loading" />
        <KpiTile :label="t('kpi.avgDaysOverdue')" :value="data?.avg_days_overdue" :suffix="' ' + t('kpi.days')" icon="pi pi-clock" tone="warning" :loading="loading" />
        <KpiTile :label="t('kpi.concentration')" :value="data?.concentration" suffix="%" :hint="t('kpi.topClientShare')" icon="pi pi-exclamation-triangle" tone="warning" :loading="loading" />
        <KpiTile :label="t('kpi.refunds')" :value="data?.refunds" money icon="pi pi-undo" :loading="loading" />
        <KpiTile :label="t('kpi.contractValue')" :value="data?.contract_value" money icon="pi pi-file" :loading="loading" />
      </div>

      <div class="grid gap-5 lg:grid-cols-2">
        <SectionCard :title="t('kpi.agingBuckets')" icon="pi pi-chart-bar">
          <div v-if="agingChart" class="h-64"><Chart type="bar" :data="agingChart.data" :options="agingChart.options" class="h-full" /></div>
          <EmptyState v-else icon="pi pi-check-circle" :title="t('kpi.noOverdue')" />
        </SectionCard>
        <SectionCard :title="t('kpi.expectedInflow')" icon="pi pi-calendar">
          <div class="grid grid-cols-3 gap-3">
            <div v-for="(k, i) in ['d30', 'd60', 'd90']" :key="k" class="rounded-lg border border-line p-3 text-center">
              <p class="text-xs font-medium text-mute">{{ [t('kpi.next30'), t('kpi.next60'), t('kpi.next90')][i] }}</p>
              <p class="num mt-1 text-lg font-semibold text-ink">{{ formatMoney(data?.expected_inflow?.[k]) }}</p>
            </div>
          </div>
        </SectionCard>
      </div>

      <SectionCard :title="t('kpi.defaultRisk')" icon="pi pi-exclamation-triangle" flush>
        <DataTable :value="data?.default_risk?.clients ?? []" :loading="loading" data-key="client" sort-mode="single">
          <template #empty><EmptyState icon="pi pi-check-circle" :title="t('kpi.noDefaultRisk')" /></template>
          <Column :header="t('kpi.client')" field="client" sortable />
          <Column :header="t('kpi.overdueInstalments')" field="overdue" sortable class="text-end">
            <template #body="{ data: r }"><span class="num">{{ r.overdue }}</span></template>
          </Column>
          <Column :header="t('kpi.overdueAmount')" field="amount" sortable class="text-end">
            <template #body="{ data: r }"><span class="num font-medium text-red-600 dark:text-red-400">{{ formatMoney(r.amount) }}</span></template>
          </Column>
        </DataTable>
      </SectionCard>
    </template>
  </div>
</template>
