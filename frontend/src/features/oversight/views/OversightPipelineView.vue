<script setup>
import { onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import PageHeader from '@/components/ui/PageHeader.vue'
import OversightList from '@/features/oversight/components/OversightList.vue'
import OversightFilters from '@/features/oversight/components/OversightFilters.vue'
import { oversightApi } from '@/features/oversight/api'
import { defaultOversightRange } from '@/features/oversight/dateRange'
import { toastError } from '@/composables/useConfirm'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { t } from '@/i18n'

const data = ref({ stuck: {}, overdue: {}, upcoming_office_visits: {} })
const loading = ref(true)
const filters = reactive({ ...defaultOversightRange(), user_id: '' })

async function load() {
  loading.value = true
  try {
    data.value = await oversightApi.pipeline({ ...filters })
  } catch (e) {
    toastError(e.response?.data?.message ?? t('oversight.pipelineLoadFailed'))
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
  { key: 'client', label: t('clients.client') },
  { key: 'location', label: t('inventory.project') },
  { key: 'step', label: 'Step' },
  { key: 'created_by', label: 'Owner' },
  { key: 'created_at', label: t('project.opened'), type: 'date' },
]
const overdueCols = [
  { key: 'client', label: t('clients.client') },
  { key: 'type', label: t('oversight.action') },
  { key: 'due_at', label: t('oversight.wasDue'), type: 'date' },
  { key: 'assigned_to', label: t('pipeline.assignedTo') },
]
const officeVisitCols = [
  { key: 'client', label: t('clients.client') },
  { key: 'agent', label: 'Agent' },
  { key: 'scheduled_at', label: t('status.scheduled'), type: 'date' },
]
</script>

<template>
  <div>
    <PageHeader
:title="$t('oversight.pipelineTitle')"
      :subtitle="$t('oversight.pipelineSubtitle')"
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
:title="$t('oversight.stuckProjects')"
        icon="pi pi-hourglass"
        :data="data.stuck"
        :columns="stuckCols"
        :row-to="subjectLink"
        :empty-text="$t('oversight.stuckEmpty')"
      />
      <OversightList
:title="$t('oversight.overdueActions')"
        icon="pi pi-exclamation-circle"
        :data="data.overdue"
        :columns="overdueCols"
        :row-to="subjectLink"
        :empty-text="$t('oversight.overdueEmpty')"
      />
      <div>
        <!-- The organising view lives on its own page now — this list stays
             as the filterable anomaly monitor. -->
        <div class="mb-2 flex justify-end">
          <RouterLink
            :to="{ name: 'oversight.officeProgram' }"
            class="flex items-center gap-1.5 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
          >
            <i class="pi pi-calendar text-xs" aria-hidden="true" />
            {{ $t('oversight.openProgram') }}
          </RouterLink>
        </div>
        <OversightList
:title="$t('oversight.upcomingOffice')"
          icon="pi pi-building"
          :data="data.upcoming_office_visits"
          :columns="officeVisitCols"
          :row-to="subjectLink"
          :empty-text="$t('oversight.officeEmpty')"
        />
      </div>
    </div>
  </div>
</template>
