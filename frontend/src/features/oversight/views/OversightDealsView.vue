<script setup>
import { onMounted, reactive, ref } from 'vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import OversightList from '@/features/oversight/components/OversightList.vue'
import OversightFilters from '@/features/oversight/components/OversightFilters.vue'
import { oversightApi } from '@/features/oversight/api'
import { defaultOversightRange } from '@/features/oversight/dateRange'
import { toastError } from '@/composables/useConfirm'

const data = ref({})
const loading = ref(true)
const filters = reactive({ ...defaultOversightRange(), user_id: '' })

async function load() {
  loading.value = true
  try {
    data.value = await oversightApi.lostPaidDeals({ ...filters })
  } catch (e) {
    toastError(e.response?.data?.message ?? 'Could not load deal oversight.')
  } finally {
    loading.value = false
  }
}
onMounted(load)

const dealLink = (item) =>
  item.link?.client_id && item.link?.project_id
    ? { name: 'clients.project', params: { id: item.link.client_id, projectId: item.link.project_id } }
    : null

const cols = [
  { key: 'client', label: 'Client' },
  { key: 'collected', label: 'Collected', type: 'money' },
  { key: 'created_by', label: 'Opened by' },
  { key: 'created_at', label: 'Opened', type: 'date' },
]

const visitCols = [
  { key: 'client', label: 'Client' },
  { key: 'type', label: 'Visit' },
  { key: 'project_step', label: 'Project' },
  { key: 'scheduled_at', label: 'Scheduled', type: 'date' },
  { key: 'agent', label: 'Agent' },
]
</script>

<template>
  <div>
    <PageHeader
      title="Deal follow-through"
      subtitle="Financial risk and loose ends: refunds to track, and visits still scheduled after the deal concluded."
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
        title="Lost deals that took payments"
        icon="pi pi-wallet"
        :data="data.lost_paid ?? {}"
        :columns="cols"
        :row-to="dealLink"
        empty-text="No lost deal is carrying unrefunded payments."
      />
      <!-- Nothing may stay "Scheduled" once the deal decided the thread —
           these visits must be completed (or their project reactivated). -->
      <OversightList
        title="Visits still open after the deal concluded"
        icon="pi pi-calendar-times"
        :data="data.stale_visits ?? {}"
        :columns="visitCols"
        :row-to="dealLink"
        empty-text="No visit is lingering on a concluded project."
      />
    </div>
  </div>
</template>
