<script setup>
import { computed } from 'vue'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import KpiTile from '@/features/analytics/components/kpi/KpiTile.vue'
import { useKpiSection } from '@/features/analytics/kpi/useKpiSection'
import { formatMoney } from '@/features/payments/money'
import { t } from '@/i18n'

const props = defineProps({ params: { type: Object, required: true } })
const emit = defineEmits(['edit-costs'])
const paramsRef = computed(() => props.params)
const { data, loading, error } = useKpiSection('profitability', paramsRef)

const totals = computed(() => data.value?.totals ?? null)
</script>

<template>
  <div class="space-y-5">
    <SectionCard v-if="error"><EmptyState icon="pi pi-exclamation-triangle" :title="error" /></SectionCard>

    <SectionCard v-else-if="!loading && !data?.configured">
      <EmptyState icon="pi pi-calculator" :title="t('kpi.noCostsTitle')" :body="t('kpi.noCostsBody')">
        <Button :label="t('kpi.setCosts')" icon="pi pi-plus" @click="emit('edit-costs')" />
      </EmptyState>
    </SectionCard>

    <template v-else>
      <div class="grid grid-cols-2 gap-4 lg:grid-cols-3">
        <KpiTile :label="t('kpi.grossMargin')" :value="totals?.gross_margin" suffix="%" icon="pi pi-percentage" tone="success" :loading="loading" />
        <KpiTile :label="t('kpi.roi')" :value="totals?.roi" suffix="%" icon="pi pi-chart-line" tone="info" :loading="loading" />
        <KpiTile :label="t('kpi.breakEven')" :value="totals?.break_even" suffix="%" icon="pi pi-flag" tone="warning" :loading="loading" />
        <KpiTile :label="t('kpi.totalCost')" :value="totals?.cost" money icon="pi pi-wallet" :loading="loading" />
        <KpiTile :label="t('kpi.salesValue')" :value="totals?.sales_value" money icon="pi pi-dollar" :loading="loading" />
        <KpiTile :label="t('kpi.collected')" :value="totals?.collected" money icon="pi pi-check-circle" :loading="loading" />
      </div>

      <SectionCard :title="t('kpi.byDevelopment')" icon="pi pi-building" flush>
        <template #actions>
          <Button :label="t('kpi.editCosts')" icon="pi pi-pencil" size="small" text @click="emit('edit-costs')" />
        </template>
        <DataTable :value="data?.developments ?? []" :loading="loading" data-key="location_id" sort-mode="single">
          <Column :header="t('kpi.development')" field="location" sortable />
          <Column :header="t('kpi.cost')" field="cost" sortable class="text-end">
            <template #body="{ data: r }"><span class="num">{{ formatMoney(r.cost) }}</span></template>
          </Column>
          <Column :header="t('kpi.salesValue')" field="sales_value" sortable class="text-end">
            <template #body="{ data: r }"><span class="num">{{ formatMoney(r.sales_value) }}</span></template>
          </Column>
          <Column :header="t('kpi.grossMargin')" field="gross_margin" sortable class="text-end">
            <template #body="{ data: r }"><span class="num" :class="r.gross_margin >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">{{ r.gross_margin }}%</span></template>
          </Column>
          <Column :header="t('kpi.roi')" field="roi" sortable class="text-end">
            <template #body="{ data: r }"><span class="num">{{ r.roi }}%</span></template>
          </Column>
          <Column :header="t('kpi.breakEven')" field="break_even" sortable class="text-end">
            <template #body="{ data: r }"><span class="num">{{ r.break_even }}%</span></template>
          </Column>
        </DataTable>
      </SectionCard>
    </template>
  </div>
</template>
