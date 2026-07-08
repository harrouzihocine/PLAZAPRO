<script setup>
import { onMounted, reactive, ref } from 'vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import OversightList from '@/features/oversight/components/OversightList.vue'
import OversightFilters from '@/features/oversight/components/OversightFilters.vue'
import { oversightApi } from '@/features/oversight/api'
import { defaultOversightRange } from '@/features/oversight/dateRange'
import { toastError } from '@/composables/useConfirm'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { t } from '@/i18n'

const data = ref({})
const loading = ref(true)
const filters = reactive({ ...defaultOversightRange(), user_id: '' })

async function load() {
  loading.value = true
  try {
    data.value = await oversightApi.lostPaidDeals({ ...filters })
  } catch (e) {
    toastError(e.response?.data?.message ?? t('oversight.dealsLoadFailed'))
  } finally {
    loading.value = false
  }
}
onMounted(load)
useRefreshable(load) // pull-to-refresh + reconnect self-heal

const dealLink = (item) =>
  item.link?.client_id && item.link?.project_id
    ? { name: 'clients.project', params: { id: item.link.client_id, projectId: item.link.project_id } }
    : null

const cols = [
  { key: 'client', label: t('clients.client') },
  { key: 'collected', label: t('inventory.collected'), type: 'money' },
  { key: 'created_by', label: t('project.openedBy') },
  { key: 'created_at', label: t('project.opened'), type: 'date' },
]

const visitCols = [
  { key: 'client', label: t('clients.client') },
  { key: 'type', label: 'Visit' },
  { key: 'project_step', label: t('inventory.project') },
  { key: 'scheduled_at', label: t('status.scheduled'), type: 'date' },
  { key: 'agent', label: 'Agent' },
]
</script>

<template>
  <div>
    <PageHeader
:title="$t('oversight.dealsTitle')"
      :subtitle="$t('oversight.dealsSubtitle')"
    />
    <OversightFilters
      v-model:from="filters.from"
      v-model:to="filters.to"
      v-model:user-id="filters.user_id"
      @apply="load"
    />
    <p v-if="loading" class="py-6 text-center text-sm text-mute">Loading…</p>
    <div v-else class="space-y-5">
      <OversightList
:title="$t('oversight.lostWithPayments')"
        icon="pi pi-wallet"
        :data="data.lost_paid ?? {}"
        :columns="cols"
        :row-to="dealLink"
        empty-text="No lost deal is carrying unrefunded payments."
      />
      <!-- Nothing may stay "Scheduled" once the deal decided the thread —
           these visits must be completed (or their project reactivated). -->
      <OversightList
:title="$t('oversight.visitsAfterDeal')"
        icon="pi pi-calendar-times"
        :data="data.stale_visits ?? {}"
        :columns="visitCols"
        :row-to="dealLink"
        empty-text="No visit is lingering on a concluded project."
      />
    </div>
  </div>
</template>
