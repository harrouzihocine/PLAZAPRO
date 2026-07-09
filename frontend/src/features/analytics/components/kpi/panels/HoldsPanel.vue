<script setup>
import { computed } from 'vue'
import Chart from 'primevue/chart'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import KpiTile from '@/features/analytics/components/kpi/KpiTile.vue'
import { useKpiSection } from '@/features/analytics/kpi/useKpiSection'
import { donut, rankedBar, chartTokens } from '@/features/analytics/kpi/charts'
import { t } from '@/i18n'

const props = defineProps({ params: { type: Object, required: true } })
const paramsRef = computed(() => props.params)
const { data, loading, error } = useKpiSection('holds', paramsRef)

const outcomeChart = computed(() => {
  const o = data.value?.outcomes ?? []
  if (!o.some((r) => r.count)) return null
  const c = chartTokens()
  const colorFor = { active: c.info, converted: c.success, expired: c.danger, released: c.mute }
  return donut(
    o.map((r) => t(`kpi.hold_${r.status}`)),
    o.map((r) => r.count),
    o.map((r) => colorFor[r.status]),
  )
})

const locationChart = computed(() => {
  const rows = data.value?.by_location ?? []
  if (!rows.length) return null
  return rankedBar(rows.map((r) => r.location), rows.map((r) => r.count), 'info')
})
</script>

<template>
  <div class="space-y-5">
    <SectionCard v-if="error"><EmptyState icon="pi pi-exclamation-triangle" :title="error" /></SectionCard>

    <template v-else>
      <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <KpiTile :label="t('kpi.activeHolds')" :value="data?.active_holds" icon="pi pi-bookmark" tone="info" :loading="loading" />
        <KpiTile :label="t('kpi.newHolds')" :value="data?.new_holds" icon="pi pi-plus-circle" :loading="loading" />
        <KpiTile :label="t('kpi.holdConversion')" :value="data?.conversion_rate" suffix="%" icon="pi pi-check-circle" tone="success" :loading="loading" />
        <KpiTile :label="t('kpi.reliabilityIndex')" :value="data?.reliability_index" suffix="%" icon="pi pi-verified" :loading="loading" />
        <KpiTile :label="t('kpi.avgHoldDuration')" :value="data?.avg_duration_days ?? '—'" :suffix="data?.avg_duration_days != null ? ' ' + t('kpi.days') : ''" icon="pi pi-clock" :loading="loading" />
        <KpiTile :label="t('kpi.converted')" :value="data?.converted" icon="pi pi-arrow-right" tone="success" :loading="loading" />
        <KpiTile :label="t('kpi.expiredHolds')" :value="data?.expired" icon="pi pi-times-circle" tone="danger" :loading="loading" />
        <KpiTile :label="t('kpi.releasedHolds')" :value="data?.released" icon="pi pi-undo" :loading="loading" />
      </div>

      <div class="grid gap-5 lg:grid-cols-2">
        <SectionCard :title="t('kpi.holdOutcomes')" icon="pi pi-chart-pie">
          <div v-if="outcomeChart" class="h-64"><Chart type="doughnut" :data="outcomeChart.data" :options="outcomeChart.options" class="h-full" /></div>
          <EmptyState v-else icon="pi pi-bookmark" :title="t('kpi.noHolds')" />
        </SectionCard>
        <SectionCard :title="t('kpi.activeHoldsByDevelopment')" icon="pi pi-building">
          <div v-if="locationChart" class="h-64"><Chart type="bar" :data="locationChart.data" :options="locationChart.options" class="h-full" /></div>
          <EmptyState v-else icon="pi pi-building" :title="t('kpi.noHolds')" />
        </SectionCard>
      </div>
    </template>
  </div>
</template>
