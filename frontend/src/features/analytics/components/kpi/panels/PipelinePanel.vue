<script setup>
import { computed } from 'vue'
import Chart from 'primevue/chart'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import KpiTile from '@/features/analytics/components/kpi/KpiTile.vue'
import { useKpiSection } from '@/features/analytics/kpi/useKpiSection'
import { rankedBar } from '@/features/analytics/kpi/charts'
import { t } from '@/i18n'

const props = defineProps({ params: { type: Object, required: true } })
const paramsRef = computed(() => props.params)
const { data, loading, error } = useKpiSection('pipeline', paramsRef)

// The funnel as four stages with proportional bars + between-stage conversion.
const funnel = computed(() => {
  const f = data.value?.funnel
  if (!f) return []
  const max = Math.max(f.leads, 1)
  return [
    { key: 'leads', label: t('kpi.leads'), count: f.leads, pct: 100, conv: null },
    { key: 'visits', label: t('kpi.visits'), count: f.visits, pct: (f.visits / max) * 100, conv: f.lead_to_visit },
    { key: 'reservations', label: t('kpi.reservations'), count: f.reservations, pct: (f.reservations / max) * 100, conv: f.visit_to_reservation },
    { key: 'contracts', label: t('kpi.contracts'), count: f.contracts, pct: (f.contracts / max) * 100, conv: f.reservation_to_contract },
  ]
})

const sourceChart = computed(() => {
  const rows = data.value?.new_leads?.by_source ?? []
  if (!rows.length) return null
  return rankedBar(rows.map((r) => r.source), rows.map((r) => r.leads), 'primary')
})
const lostChart = computed(() => {
  const rows = data.value?.lost_reasons ?? []
  if (!rows.length) return null
  return rankedBar(rows.map((r) => r.reason), rows.map((r) => r.count), 'danger')
})
</script>

<template>
  <div class="space-y-5">
    <SectionCard v-if="error"><EmptyState icon="pi pi-exclamation-triangle" :title="error" /></SectionCard>

    <template v-else>
      <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <KpiTile :label="t('kpi.newLeads')" :value="data?.new_leads?.total" icon="pi pi-user-plus" :loading="loading" />
        <KpiTile :label="t('kpi.overdueActions')" :value="data?.overdue_actions?.total" icon="pi pi-exclamation-circle" tone="danger" :loading="loading" />
        <KpiTile :label="t('kpi.endToEnd')" :value="data?.end_to_end" suffix="%" icon="pi pi-flag-fill" tone="success" :loading="loading" />
        <KpiTile :label="t('kpi.salesCycle')" :value="data?.sales_cycle_days ?? '—'" :suffix="data?.sales_cycle_days != null ? ' ' + t('kpi.days') : ''" icon="pi pi-clock" :loading="loading" />
        <KpiTile :label="t('kpi.staleLeads')" :value="data?.stale_leads" :hint="t('kpi.over30Days')" icon="pi pi-inbox" tone="warning" :loading="loading" />
        <KpiTile :label="t('kpi.pipelineValueWeighted')" :value="data?.pipeline_value?.weighted" money icon="pi pi-sliders-h" tone="info" :loading="loading" />
        <KpiTile :label="t('kpi.pipelineValueRaw')" :value="data?.pipeline_value?.raw" money icon="pi pi-dollar" :loading="loading" />
      </div>

      <SectionCard :title="t('kpi.conversionFunnel')" icon="pi pi-filter">
        <div class="space-y-3">
          <div v-for="stage in funnel" :key="stage.key">
            <div class="mb-1 flex items-center justify-between text-sm">
              <span class="font-medium text-ink">{{ stage.label }}</span>
              <span class="flex items-center gap-2">
                <span v-if="stage.conv !== null" class="text-xs text-mute">{{ stage.conv }}%</span>
                <span class="num font-semibold text-ink">{{ stage.count }}</span>
              </span>
            </div>
            <div class="h-3 overflow-hidden rounded-full bg-surface-100 dark:bg-surface-800">
              <span class="block h-full rounded-full bg-primary-600 dark:bg-primary-400" :style="{ width: `${Math.max(2, stage.pct)}%` }" aria-hidden="true" />
            </div>
          </div>
        </div>
      </SectionCard>

      <div class="grid gap-5 lg:grid-cols-2">
        <SectionCard :title="t('kpi.leadsBySource')" icon="pi pi-share-alt">
          <div v-if="sourceChart" class="h-64"><Chart type="bar" :data="sourceChart.data" :options="sourceChart.options" class="h-full" /></div>
          <EmptyState v-else icon="pi pi-share-alt" :title="t('kpi.noLeads')" />
        </SectionCard>
        <SectionCard :title="t('kpi.lostReasons')" icon="pi pi-times-circle">
          <div v-if="lostChart" class="h-64"><Chart type="bar" :data="lostChart.data" :options="lostChart.options" class="h-full" /></div>
          <EmptyState v-else icon="pi pi-check-circle" :title="t('kpi.noLosses')" />
        </SectionCard>
      </div>

      <SectionCard v-if="data?.overdue_actions?.by_agent?.length" :title="t('kpi.overdueByAgent')" icon="pi pi-users" flush>
        <DataTable :value="data.overdue_actions.by_agent" data-key="agent" sort-mode="single">
          <Column :header="t('kpi.agent')" field="agent" sortable />
          <Column :header="t('kpi.overdue')" field="count" sortable class="text-end">
            <template #body="{ data: r }"><span class="num font-medium text-red-600 dark:text-red-400">{{ r.count }}</span></template>
          </Column>
        </DataTable>
      </SectionCard>
    </template>
  </div>
</template>
