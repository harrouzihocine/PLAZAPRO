<script setup>
import { onMounted, reactive, ref } from 'vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import OversightList from '@/features/oversight/components/OversightList.vue'
import OversightFilters from '@/features/oversight/components/OversightFilters.vue'
import { oversightApi } from '@/features/oversight/api'
import { defaultOversightRange } from '@/features/oversight/dateRange'
import { toastError } from '@/composables/useConfirm'
import { useRefreshable } from '@/composables/useRefreshRegistry'

const data = ref({ stuck: {}, overdue: {}, upcoming_office_visits: {} })
const loading = ref(true)
const filters = reactive({ ...defaultOversightRange(), user_id: '' })

async function load() {
  loading.value = true
  try {
    data.value = await oversightApi.pipeline({ ...filters })
  } catch (e) {
    toastError(e.response?.data?.message ?? 'Could not load pipeline oversight.')
  } finally {
    loading.value = false
  }
}
onMounted(load)
useRefreshable(load) // pull-to-refresh + reconnect self-heal

// A next-action / project row opens the project (or the client when no project).
function subjectLink(item) {
  const l = item.link
  if (!l?.client_id) return null
  return l.project_id
    ? { name: 'clients.project', params: { id: l.client_id, projectId: l.project_id } }
    : { name: 'clients.file', params: { id: l.client_id } }
}

const stuckCols = [
  { key: 'client', label: 'Client' },
  { key: 'location', label: 'Project' },
  { key: 'step', label: 'Step' },
  { key: 'created_by', label: 'Owner' },
  { key: 'created_at', label: 'Opened', type: 'date' },
]
const overdueCols = [
  { key: 'client', label: 'Client' },
  { key: 'type', label: 'Action' },
  { key: 'due_at', label: 'Was due', type: 'date' },
  { key: 'assigned_to', label: 'Assigned to' },
]
const officeVisitCols = [
  { key: 'client', label: 'Client' },
  { key: 'agent', label: 'Agent' },
  { key: 'scheduled_at', label: 'Scheduled', type: 'date' },
]
</script>

<template>
  <div>
    <PageHeader
      title="Pipeline oversight"
      subtitle="Cold projects, unrespected next actions and upcoming office visits, by user."
    />
    <OversightFilters
      v-model:from="filters.from"
      v-model:to="filters.to"
      v-model:user-id="filters.user_id"
      @apply="load"
    />
    <p v-if="loading" class="py-6 text-center text-sm text-mute">Loading…</p>
    <div v-else class="space-y-4">
      <OversightList
        title="Stuck projects (no next action)"
        icon="pi pi-hourglass"
        :data="data.stuck"
        :columns="stuckCols"
        :row-to="subjectLink"
        empty-text="Every active project has a planned next step."
      />
      <OversightList
        title="Overdue / unrespected next actions"
        icon="pi pi-exclamation-circle"
        :data="data.overdue"
        :columns="overdueCols"
        :row-to="subjectLink"
        empty-text="No next action is past its due date."
      />
      <OversightList
        title="Upcoming office visits"
        icon="pi pi-building"
        :data="data.upcoming_office_visits"
        :columns="officeVisitCols"
        :row-to="subjectLink"
        empty-text="No office visits scheduled ahead — nothing to organise."
      />
    </div>
  </div>
</template>
