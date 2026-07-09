<script setup>
import { computed, ref } from 'vue'
import Button from 'primevue/button'
import PageHeader from '@/components/ui/PageHeader.vue'
import KpiFilterBar from '@/features/analytics/components/kpi/KpiFilterBar.vue'
import TargetsCostsEditor from '@/features/analytics/components/kpi/TargetsCostsEditor.vue'
import ExecutivePanel from '@/features/analytics/components/kpi/panels/ExecutivePanel.vue'
import SalesPanel from '@/features/analytics/components/kpi/panels/SalesPanel.vue'
import InventoryPanel from '@/features/analytics/components/kpi/panels/InventoryPanel.vue'
import HoldsPanel from '@/features/analytics/components/kpi/panels/HoldsPanel.vue'
import PipelinePanel from '@/features/analytics/components/kpi/panels/PipelinePanel.vue'
import CollectionsPanel from '@/features/analytics/components/kpi/panels/CollectionsPanel.vue'
import AgentsPanel from '@/features/analytics/components/kpi/panels/AgentsPanel.vue'
import CancellationsPanel from '@/features/analytics/components/kpi/panels/CancellationsPanel.vue'
import ProfitabilityPanel from '@/features/analytics/components/kpi/panels/ProfitabilityPanel.vue'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { t } from '@/i18n'

// The KPI command center: one filterable board of every company-wide KPI.
// Panels self-fetch their section (watching the shared filters), so switching
// tabs loads only what's on screen.
const filters = ref({
  period: 'month',
  from: '',
  to: '',
  location_id: '',
  unit_type: '',
  agent_id: '',
})

const TABS = [
  { key: 'executive', icon: 'pi pi-compass', component: ExecutivePanel },
  { key: 'sales', icon: 'pi pi-dollar', component: SalesPanel },
  { key: 'inventory', icon: 'pi pi-th-large', component: InventoryPanel },
  { key: 'holds', icon: 'pi pi-bookmark', component: HoldsPanel },
  { key: 'pipeline', icon: 'pi pi-filter', component: PipelinePanel },
  { key: 'collections', icon: 'pi pi-wallet', component: CollectionsPanel },
  { key: 'agents', icon: 'pi pi-users', component: AgentsPanel },
  { key: 'cancellations', icon: 'pi pi-ban', component: CancellationsPanel },
  { key: 'profitability', icon: 'pi pi-calculator', component: ProfitabilityPanel },
]

const tab = ref('executive')
const activePanel = computed(() => TABS.find((x) => x.key === tab.value)?.component)

const showEditor = ref(false)
const refreshKey = ref(0)

// Pull-to-refresh (APK) + reconnect self-heal: remount the active panel.
useRefreshable(() => {
  refreshKey.value++
})
</script>

<template>
  <div>
    <PageHeader :title="t('kpi.title')" :subtitle="t('kpi.subtitle')">
      <template #actions>
        <Button :label="t('kpi.targetsCosts')" icon="pi pi-sliders-h" outlined size="small" @click="showEditor = true" />
      </template>
    </PageHeader>

    <div class="space-y-4">
      <KpiFilterBar v-model="filters" />

      <!-- Tab nav — horizontally scrollable on phones -->
      <nav class="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1">
        <button
          v-for="x in TABS"
          :key="x.key"
          type="button"
          class="flex shrink-0 items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors"
          :class="
            tab === x.key
              ? 'border-primary-300 bg-highlight font-semibold text-ink dark:border-primary-500/40'
              : 'border-line bg-card text-mute hover:text-ink'
          "
          @click="tab = x.key"
        >
          <i :class="x.icon" aria-hidden="true" />
          {{ t(`kpi.tab_${x.key}`) }}
        </button>
      </nav>

      <component
        :is="activePanel"
        :key="`${tab}-${refreshKey}`"
        :params="filters"
        @edit-costs="showEditor = true"
      />
    </div>

    <TargetsCostsEditor
      v-if="showEditor"
      @close="showEditor = false"
      @saved="refreshKey++"
    />
  </div>
</template>
