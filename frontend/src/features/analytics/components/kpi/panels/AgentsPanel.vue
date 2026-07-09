<script setup>
import { computed } from 'vue'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useKpiSection } from '@/features/analytics/kpi/useKpiSection'
import { formatMoney } from '@/features/payments/money'
import { t } from '@/i18n'

const props = defineProps({ params: { type: Object, required: true } })
const paramsRef = computed(() => props.params)
const { data, loading, error } = useKpiSection('agents', paramsRef)

const rows = computed(() => (data.value?.agents ?? []).map((a, i) => ({ ...a, rank: i + 1 })))
const medal = (rank) => ({ 1: '🥇', 2: '🥈', 3: '🥉' })[rank] ?? null
</script>

<template>
  <SectionCard v-if="error"><EmptyState icon="pi pi-exclamation-triangle" :title="error" /></SectionCard>

  <SectionCard v-else :title="t('kpi.leaderboard')" icon="pi pi-trophy" flush>
    <DataTable :value="rows" :loading="loading" data-key="agent_id" sort-mode="single" paginator :rows="25">
      <template #empty><EmptyState icon="pi pi-users" :title="t('kpi.noAgentActivity')" /></template>
      <Column :header="t('kpi.rank')" class="w-14">
        <template #body="{ data: r }">
          <span class="font-semibold">{{ medal(r.rank) ?? r.rank }}</span>
        </template>
      </Column>
      <Column :header="t('kpi.agent')" field="agent" sortable>
        <template #body="{ data: r }"><span class="font-medium text-ink">{{ r.agent }}</span></template>
      </Column>
      <Column :header="t('kpi.sales')" field="sales_count" sortable class="text-end">
        <template #body="{ data: r }"><span class="num">{{ r.sales_count }}</span></template>
      </Column>
      <Column :header="t('kpi.salesValue')" field="sales_value" sortable class="text-end">
        <template #body="{ data: r }"><span class="num">{{ formatMoney(r.sales_value) }}</span></template>
      </Column>
      <Column :header="t('kpi.activities')" field="activities" sortable class="text-end">
        <template #body="{ data: r }"><span class="num">{{ r.activities }}</span></template>
      </Column>
      <Column :header="t('kpi.collections')" field="collections" sortable class="text-end">
        <template #body="{ data: r }"><span class="num">{{ formatMoney(r.collections) }}</span></template>
      </Column>
      <Column :header="t('kpi.overdue')" field="overdue" sortable class="text-end">
        <template #body="{ data: r }"><span class="num" :class="r.overdue > 0 ? 'text-red-600 dark:text-red-400' : ''">{{ r.overdue }}</span></template>
      </Column>
      <Column :header="t('kpi.workload')" field="workload" sortable class="text-end">
        <template #body="{ data: r }"><span class="num">{{ r.workload }}</span></template>
      </Column>
      <Column :header="t('kpi.cancelRate')" field="cancellation_rate" sortable class="text-end">
        <template #body="{ data: r }"><span class="num">{{ r.cancellation_rate }}%</span></template>
      </Column>
      <Column :header="t('kpi.score')" field="score" sortable class="text-end">
        <template #body="{ data: r }"><span class="num font-semibold text-primary-700 dark:text-primary-300">{{ r.score }}</span></template>
      </Column>
    </DataTable>
  </SectionCard>
</template>
