<script setup>
import { computed, ref, watch } from 'vue'
import Chart from 'primevue/chart'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import KpiTile from '@/features/analytics/components/kpi/KpiTile.vue'
import { useKpiSection } from '@/features/analytics/kpi/useKpiSection'
import { analyticsApi } from '@/features/analytics/api'
import { timeLine } from '@/features/analytics/kpi/charts'
import { formatMoney } from '@/features/payments/money'
import { t } from '@/i18n'

const props = defineProps({ params: { type: Object, required: true } })
const paramsRef = computed(() => props.params)
const { data, loading, error } = useKpiSection('overview', paramsRef)

// Target attainment progress (only when a target is configured for the window).
const target = computed(() => data.value?.target ?? null)

// ── Trend curves (from nightly snapshots) ──────────────────────────────────
const trends = ref(null)
async function loadTrends() {
  try {
    trends.value = await analyticsApi.kpiTrends({
      days: 90,
      location_id: props.params.location_id || undefined,
    })
  } catch {
    trends.value = null
  }
}
watch(() => props.params.location_id, loadTrends, { immediate: true })

const cashChart = computed(() => {
  if (!trends.value) return null
  return timeLine([
    { label: t('kpi.salesValue'), points: trends.value.sales_value ?? [], color: 'primary', money: true },
    { label: t('kpi.collected'), points: trends.value.collected ?? [], color: 'success', money: true },
  ])
})
const balanceChart = computed(() => {
  if (!trends.value) return null
  return timeLine([
    { label: t('kpi.outstanding'), points: trends.value.receivables_outstanding ?? [], color: 'danger', money: true },
    { label: t('kpi.inventoryValue'), points: trends.value.inventory_value ?? [], color: 'info', money: true },
  ])
})
const hasTrend = computed(() =>
  (trends.value?.sales_value?.length ?? 0) + (trends.value?.collected?.length ?? 0) > 0,
)
</script>

<template>
  <div class="space-y-5">
    <SectionCard v-if="error"><EmptyState icon="pi pi-exclamation-triangle" :title="error" /></SectionCard>

    <template v-else>
      <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <KpiTile
          :label="t('kpi.salesValue')" :value="data?.sales_value?.value" money
          :delta="data?.sales_value?.delta" icon="pi pi-dollar" tone="success" :loading="loading"
        />
        <KpiTile
          :label="t('kpi.unitsSold')" :value="data?.units_sold?.total"
          :delta="data?.units_sold?.delta" icon="pi pi-home" :loading="loading"
        />
        <KpiTile
          :label="t('kpi.collected')" :value="data?.collected?.value" money
          :delta="data?.collected?.delta" icon="pi pi-wallet" tone="info" :loading="loading"
        />
        <KpiTile
          :label="t('kpi.netAbsorption')" :value="data?.net_sales_value?.value" money
          icon="pi pi-chart-line" :loading="loading"
        />
        <KpiTile
          :label="t('kpi.outstanding')" :value="data?.outstanding" money
          icon="pi pi-hourglass" tone="warning" :loading="loading"
        />
        <KpiTile
          :label="t('kpi.overdueAmount')" :value="data?.overdue_amount" money
          icon="pi pi-exclamation-circle" tone="danger" :loading="loading"
        />
        <KpiTile
          :label="t('kpi.sellThrough')" :value="data?.sell_through" suffix="%"
          icon="pi pi-percentage" :loading="loading"
        />
        <KpiTile
          :label="t('kpi.activeHolds')" :value="data?.active_holds"
          icon="pi pi-bookmark" tone="info" :loading="loading"
        />
      </div>

      <!-- Target attainment -->
      <SectionCard v-if="target" :title="t('kpi.targetAttainment')" icon="pi pi-flag">
        <div class="flex items-center gap-4">
          <div class="relative h-3 flex-1 overflow-hidden rounded-full bg-surface-100 dark:bg-surface-800">
            <span
              class="absolute inset-y-0 start-0 rounded-full bg-primary-600 dark:bg-primary-400"
              :style="{ width: `${Math.min(100, target.attainment)}%` }"
              aria-hidden="true"
            />
          </div>
          <span class="num font-semibold text-ink">{{ target.attainment }}%</span>
        </div>
        <p class="mt-2 text-sm text-mute">
          {{ formatMoney(target.actual) }} / {{ formatMoney(target.amount) }}
        </p>
      </SectionCard>

      <!-- Trend curves -->
      <div class="grid gap-5 lg:grid-cols-2">
        <SectionCard :title="t('kpi.cashCurve')" icon="pi pi-chart-line">
          <div v-if="hasTrend" class="h-64"><Chart type="line" :data="cashChart.data" :options="cashChart.options" class="h-full" /></div>
          <EmptyState v-else icon="pi pi-chart-line" :title="t('kpi.noTrendYet')" :body="t('kpi.noTrendBody')" />
        </SectionCard>
        <SectionCard :title="t('kpi.balancesCurve')" icon="pi pi-chart-bar">
          <div v-if="hasTrend" class="h-64"><Chart type="line" :data="balanceChart.data" :options="balanceChart.options" class="h-full" /></div>
          <EmptyState v-else icon="pi pi-chart-bar" :title="t('kpi.noTrendYet')" :body="t('kpi.noTrendBody')" />
        </SectionCard>
      </div>
    </template>
  </div>
</template>
