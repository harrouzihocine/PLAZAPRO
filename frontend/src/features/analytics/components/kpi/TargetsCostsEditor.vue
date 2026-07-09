<script setup>
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import BaseModal from '@/components/base/BaseModal.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import MoneyInput from '@/components/base/MoneyInput.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { analyticsApi } from '@/features/analytics/api'
import { formatMoney } from '@/features/payments/money'
import { toastSuccess, toastError } from '@/composables/useConfirm'
import { t } from '@/i18n'

const emit = defineEmits(['close', 'saved'])
const tab = ref('costs')

// ── Costs ──────────────────────────────────────────────────────────────────
const costs = ref([])
const locations = ref([])
const costForm = ref({ location_id: '', land_cost: null, construction_cost: null, fees: null, notes: '' })
const savingCost = ref(false)

const locationOptions = computed(() => locations.value.map((l) => ({ value: l.id, label: l.name })))

async function loadCosts() {
  const res = await analyticsApi.kpiCosts()
  costs.value = res.costs ?? []
  locations.value = res.locations ?? []
}
function pickLocation(id) {
  costForm.value.location_id = id
  const existing = costs.value.find((c) => c.location_id === id)
  costForm.value.land_cost = existing?.land_cost ?? null
  costForm.value.construction_cost = existing?.construction_cost ?? null
  costForm.value.fees = existing?.fees ?? null
  costForm.value.notes = existing?.notes ?? ''
}
async function saveCost() {
  if (!costForm.value.location_id) return
  savingCost.value = true
  try {
    await analyticsApi.saveKpiCost({
      location_id: costForm.value.location_id,
      land_cost: costForm.value.land_cost ?? 0,
      construction_cost: costForm.value.construction_cost ?? 0,
      fees: costForm.value.fees ?? 0,
      notes: costForm.value.notes || null,
    })
    toastSuccess(t('kpi.costsSaved'))
    await loadCosts()
    emit('saved')
  } catch {
    toastError(t('kpi.saveFailed'))
  } finally {
    savingCost.value = false
  }
}

// ── Targets ─────────────────────────────────────────────────────────────────
const targets = ref([])
const agents = ref([])
const targetForm = ref({ scope: 'company', scope_id: '', period_type: 'month', period_start: '', metric: 'sales_value', target_amount: null })
const savingTarget = ref(false)

const scopeOptions = [
  { value: 'company', label: t('kpi.scopeCompany') },
  { value: 'agent', label: t('kpi.scopeAgent') },
  { value: 'location', label: t('kpi.scopeDevelopment') },
]
const periodOptions = [
  { value: 'month', label: t('kpi.period_month') },
  { value: 'quarter', label: t('kpi.period_quarter') },
  { value: 'year', label: t('kpi.period_year') },
]
const metricOptions = [
  { value: 'sales_value', label: t('kpi.metricSalesValue') },
  { value: 'units_sold', label: t('kpi.metricUnitsSold') },
  { value: 'collections', label: t('kpi.metricCollections') },
]
const scopeIdOptions = computed(() =>
  targetForm.value.scope === 'agent'
    ? agents.value.map((a) => ({ value: a.id, label: a.name }))
    : locationOptions.value,
)
const isMoneyMetric = computed(() => targetForm.value.metric !== 'units_sold')

async function loadTargets() {
  targets.value = await analyticsApi.kpiTargets().then((r) => r.targets ?? [])
  if (!agents.value.length) {
    try {
      agents.value = (await analyticsApi.kpiFilters()).agents ?? []
    } catch { /* agent select stays empty */ }
  }
}

// The window must start on the first day of its period so it matches the KPI query.
function normalizePeriodStart(dateStr, periodType) {
  if (!dateStr) return null
  const d = new Date(dateStr)
  const y = d.getFullYear()
  if (periodType === 'year') return `${y}-01-01`
  if (periodType === 'quarter') {
    const qStartMonth = Math.floor(d.getMonth() / 3) * 3 // 0,3,6,9
    return `${y}-${String(qStartMonth + 1).padStart(2, '0')}-01`
  }
  return `${y}-${String(d.getMonth() + 1).padStart(2, '0')}-01`
}

async function saveTarget() {
  const start = normalizePeriodStart(targetForm.value.period_start, targetForm.value.period_type)
  if (!start || targetForm.value.target_amount == null) {
    toastError(t('kpi.targetIncomplete'))
    return
  }
  savingTarget.value = true
  try {
    await analyticsApi.saveKpiTarget({
      scope: targetForm.value.scope,
      scope_id: targetForm.value.scope === 'company' ? null : targetForm.value.scope_id || null,
      scope_key: null,
      period_type: targetForm.value.period_type,
      period_start: start,
      metric: targetForm.value.metric,
      target_amount: targetForm.value.target_amount,
    })
    toastSuccess(t('kpi.targetSaved'))
    await loadTargets()
    emit('saved')
  } catch {
    toastError(t('kpi.saveFailed'))
  } finally {
    savingTarget.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadCosts(), loadTargets()])
})
</script>

<template>
  <BaseModal :title="t('kpi.editorTitle')" size="max-w-3xl" @close="emit('close')">
    <nav class="mb-4 inline-flex rounded-lg border border-line bg-card p-1">
      <button type="button" class="rounded-md px-4 py-1.5 text-sm" :class="tab === 'costs' ? 'bg-highlight font-semibold text-ink' : 'text-mute'" @click="tab = 'costs'">
        {{ t('kpi.developmentCosts') }}
      </button>
      <button type="button" class="rounded-md px-4 py-1.5 text-sm" :class="tab === 'targets' ? 'bg-highlight font-semibold text-ink' : 'text-mute'" @click="tab = 'targets'">
        {{ t('kpi.salesTargets') }}
      </button>
    </nav>

    <!-- Costs -->
    <div v-if="tab === 'costs'" class="space-y-4">
      <BaseSelect :model-value="costForm.location_id" :options="locationOptions" :label="t('kpi.development')" @update:model-value="pickLocation" />
      <template v-if="costForm.location_id">
        <div class="grid gap-3 sm:grid-cols-3">
          <MoneyInput v-model="costForm.land_cost" :label="t('kpi.landCost')" />
          <MoneyInput v-model="costForm.construction_cost" :label="t('kpi.constructionCost')" />
          <MoneyInput v-model="costForm.fees" :label="t('kpi.fees')" />
        </div>
        <BaseInput v-model="costForm.notes" :label="t('common.notes')" />
        <Button :label="t('common.save')" icon="pi pi-check" :loading="savingCost" @click="saveCost" />
      </template>

      <DataTable v-if="costs.length" :value="costs" data-key="location_id" class="mt-4">
        <Column :header="t('kpi.development')" field="location.name" />
        <Column :header="t('kpi.totalCost')">
          <template #body="{ data: r }">
            <span class="num">{{ formatMoney((Number(r.land_cost) + Number(r.construction_cost) + Number(r.fees)).toFixed(2)) }}</span>
          </template>
        </Column>
      </DataTable>
    </div>

    <!-- Targets -->
    <div v-else class="space-y-4">
      <div class="grid gap-3 sm:grid-cols-2">
        <BaseSelect v-model="targetForm.scope" :options="scopeOptions" :label="t('kpi.scope')" />
        <BaseSelect v-if="targetForm.scope !== 'company'" v-model="targetForm.scope_id" :options="scopeIdOptions" searchable="auto" :label="t('kpi.target')" />
        <BaseSelect v-model="targetForm.period_type" :options="periodOptions" :label="t('kpi.periodType')" />
        <BaseInput v-model="targetForm.period_start" type="date" :label="t('kpi.periodStart')" />
        <BaseSelect v-model="targetForm.metric" :options="metricOptions" :label="t('kpi.metric')" />
        <MoneyInput v-if="isMoneyMetric" v-model="targetForm.target_amount" :label="t('kpi.targetAmount')" />
        <BaseInput v-else v-model.number="targetForm.target_amount" type="number" :label="t('kpi.targetUnits')" />
      </div>
      <Button :label="t('common.save')" icon="pi pi-check" :loading="savingTarget" @click="saveTarget" />

      <DataTable v-if="targets.length" :value="targets" data-key="id" class="mt-4">
        <template #empty><EmptyState icon="pi pi-flag" :title="t('kpi.noTargets')" /></template>
        <Column :header="t('kpi.scope')" field="scope" />
        <Column :header="t('kpi.periodStart')" field="period_start" />
        <Column :header="t('kpi.metric')" field="metric" />
        <Column :header="t('kpi.targetAmount')">
          <template #body="{ data: r }">
            <span class="num">{{ r.metric === 'units_sold' ? r.target_amount : formatMoney(r.target_amount) }}</span>
          </template>
        </Column>
      </DataTable>
    </div>
  </BaseModal>
</template>
