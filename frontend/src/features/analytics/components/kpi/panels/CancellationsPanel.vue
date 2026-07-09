<script setup>
import { computed } from 'vue'
import Chart from 'primevue/chart'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import KpiTile from '@/features/analytics/components/kpi/KpiTile.vue'
import { useKpiSection } from '@/features/analytics/kpi/useKpiSection'
import { rankedBar } from '@/features/analytics/kpi/charts'
import { t } from '@/i18n'

const props = defineProps({ params: { type: Object, required: true } })
const paramsRef = computed(() => props.params)
const { data, loading, error } = useKpiSection('cancellations', paramsRef)

const reasonChart = computed(() => {
  const rows = data.value?.reasons ?? []
  if (!rows.length) return null
  return rankedBar(rows.map((r) => r.reason), rows.map((r) => r.count), 'danger')
})
</script>

<template>
  <div class="space-y-5">
    <SectionCard v-if="error"><EmptyState icon="pi pi-exclamation-triangle" :title="error" /></SectionCard>

    <template v-else>
      <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <KpiTile :label="t('kpi.cancelRatePeriod')" :value="data?.rate_period" suffix="%" icon="pi pi-times-circle" tone="danger" :loading="loading" />
        <KpiTile :label="t('kpi.cancelRateLifetime')" :value="data?.rate_lifetime" suffix="%" icon="pi pi-history" :loading="loading" />
        <KpiTile :label="t('kpi.cancelled')" :value="data?.cancelled" icon="pi pi-ban" tone="danger" :loading="loading" />
        <KpiTile :label="t('kpi.valueCancelled')" :value="data?.value_cancelled" money icon="pi pi-dollar" tone="warning" :loading="loading" />
        <KpiTile :label="t('kpi.netAbsorptionUnits')" :value="data?.net_absorption?.units" icon="pi pi-check-circle" tone="success" :loading="loading" />
        <KpiTile :label="t('kpi.netAbsorptionValue')" :value="data?.net_absorption?.value" money icon="pi pi-chart-line" tone="success" :loading="loading" />
        <KpiTile :label="t('kpi.timeToCancel')" :value="data?.time_to_cancellation_days ?? '—'" :suffix="data?.time_to_cancellation_days != null ? ' ' + t('kpi.days') : ''" icon="pi pi-clock" :loading="loading" />
        <KpiTile :label="t('kpi.refundExposure')" :value="data?.refund_exposure" money icon="pi pi-undo" tone="warning" :loading="loading" />
      </div>

      <SectionCard :title="t('kpi.cancellationReasons')" icon="pi pi-list">
        <div v-if="reasonChart" class="h-64"><Chart type="bar" :data="reasonChart.data" :options="reasonChart.options" class="h-full" /></div>
        <EmptyState v-else icon="pi pi-check-circle" :title="t('kpi.noCancellations')" />
      </SectionCard>
    </template>
  </div>
</template>
