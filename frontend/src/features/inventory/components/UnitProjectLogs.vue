<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import Skeleton from 'primevue/skeleton'
import SectionCard from '@/components/ui/SectionCard.vue'
import StatusTag from '@/components/ui/StatusTag.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import LogTimeline from '@/features/pipeline/components/LogTimeline.vue'
import { byNewest, callEntries, visitEntries } from '@/features/pipeline/timeline'
import { unitsApi } from '@/features/inventory/api'

// The unit page's "Project logs" tab: every visible client project that has
// touched this unit, each with its interaction timeline (calls + visits) shown
// with the exact card design used on the project detail page (LogTimeline).
// Read-only — the logs are edited from their own project, not from here.
const props = defineProps({ unitId: { type: [String, Number], required: true } })

const loading = ref(true)
const failed = ref(false)
const groups = ref([])

// Merge the project's calls + visits into one newest-first story, like the
// "All" tab of the project timeline.
const entriesFor = (g) => [...callEntries(g.calls), ...visitEntries(g.visits)].sort(byNewest)

const projectTitle = (p) => p.unit_reference || p.location_name || `Project #${p.id}`

onMounted(async () => {
  try {
    groups.value = await unitsApi.projectLogs(props.unitId)
  } catch (e) {
    failed.value = true
    console.error('Failed to load unit project logs', e)
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="space-y-5">
    <div v-if="loading" class="space-y-3">
      <Skeleton height="8rem" />
      <Skeleton height="8rem" />
    </div>

    <div
      v-else-if="failed"
      class="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300"
    >
      <i class="pi pi-exclamation-triangle" aria-hidden="true" />
      Couldn't load the project logs for this unit. Try refreshing the page.
    </div>

    <SectionCard v-else-if="!groups.length" title="Project logs" icon="pi pi-history">
      <EmptyState
        icon="pi pi-history"
        title="No project logs yet"
        body="No client project has logged a call or a visit on this unit."
      />
    </SectionCard>

    <SectionCard v-for="g in groups" v-else :key="g.project.id">
      <template #header>
        <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
          <RouterLink
            :to="{
              name: 'clients.project',
              params: { id: g.project.client_id, projectId: g.project.id },
            }"
            class="text-sm font-semibold text-ink hover:underline"
          >
            {{ projectTitle(g.project) }}
          </RouterLink>
          <span v-if="g.project.client_name" class="text-xs text-mute">
            · {{ g.project.client_name }}
          </span>
          <StatusTag v-if="g.project.step" :value="g.project.step" />
          <StatusTag
            v-if="g.project.status && g.project.status !== 'active'"
            :value="g.project.status"
          />
        </div>
      </template>

      <LogTimeline
        :entries="entriesFor(g)"
        empty-title="No calls or visits yet"
        empty-body="This project has no logged calls or visits on this unit."
      />
    </SectionCard>
  </div>
</template>
