<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { analyticsApi } from '@/features/analytics/api'
import { useAuthStore } from '@/features/settings/store'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import StatCard from '@/components/ui/StatCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import WorkItemGroups from '@/features/analytics/components/WorkItemGroups.vue'

const auth = useAuthStore()

const data = ref(null)
const loading = ref(true)
const denied = ref(false)
const error = ref('')

const greeting = computed(() => {
  const h = new Date().getHours()
  if (h < 12) return 'Good morning'
  if (h < 18) return 'Good afternoon'
  return 'Good evening'
})

const firstName = computed(() => (auth.user?.name ?? '').split(' ')[0] || 'there')

// Icon per work kind — shared by "My upcoming" and "My overdue".
const KIND_ICON = {
  call: 'pi pi-phone',
  office_visit: 'pi pi-building',
  in_site_visit: 'pi pi-map-marker',
  task: 'pi pi-check-square',
}

// Shared per-type breakdown — calls, office visits, in-site visits and tasks
// are never mixed together. Feeds both "My upcoming" and "My open & overdue".
const WORK_GROUPS = [
  { key: 'calls', label: 'Calls', icon: KIND_ICON.call },
  { key: 'office_visits', label: 'Office visits', icon: KIND_ICON.office_visit },
  { key: 'in_site_visits', label: 'In-site visits', icon: KIND_ICON.in_site_visit },
  { key: 'tasks', label: 'Tasks', icon: KIND_ICON.task },
]
const myUpcoming = computed(() =>
  WORK_GROUPS.map((g) => ({ ...g, items: data.value?.my_upcoming?.[g.key] ?? [] })),
)
const hasUpcoming = computed(() => myUpcoming.value.some((g) => g.items.length))

const myOverdue = computed(() =>
  WORK_GROUPS.map((g) => ({ ...g, items: data.value?.my_overdue?.[g.key] ?? [] })),
)
const hasOverdue = computed(() => myOverdue.value.some((g) => g.items.length))

// This month's own performance — rapports logged per type + deals I closed.
const monthTiles = computed(() => {
  const s = data.value?.month_stats ?? {}
  return [
    { label: 'Calls', value: s.calls, icon: KIND_ICON.call, tone: 'info' },
    { label: 'Office visits', value: s.office_visits, icon: KIND_ICON.office_visit, tone: 'default' },
    { label: 'In-site visits', value: s.in_site_visits, icon: KIND_ICON.in_site_visit, tone: 'default' },
    { label: 'Won', value: s.won, icon: 'pi pi-check-circle', tone: 'success' },
    { label: 'Lost', value: s.lost, icon: 'pi pi-times-circle', tone: 'danger' },
  ]
})

onMounted(async () => {
  try {
    data.value = await analyticsApi.dashboard()
  } catch (e) {
    if (e.response?.status === 403) denied.value = true
    else error.value = 'Could not load the dashboard. Please try again.'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <PageHeader :title="`${greeting}, ${firstName}`">
      <template #subtitle>Here's your own book at a glance today.</template>
    </PageHeader>

    <SectionCard v-if="denied">
      <EmptyState
        icon="pi pi-lock"
        title="No dashboard access"
        body="Your role doesn't include dashboard metrics. Contact your administrator."
      />
    </SectionCard>

    <SectionCard v-else-if="error">
      <EmptyState icon="pi pi-exclamation-triangle" :title="error" />
    </SectionCard>

    <div v-else class="space-y-5">
      <!-- KPI row — personal to me -->
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          label="My active clients"
          :value="data?.kpis.clients ?? '—'"
          icon="pi pi-users"
          :loading="loading"
        />
        <StatCard
          label="My active projects"
          :value="data?.kpis.active_projects ?? '—'"
          icon="pi pi-sitemap"
          tone="info"
          :loading="loading"
        />
        <StatCard
          label="My open deals"
          :value="data?.kpis.open_deals ?? '—'"
          icon="pi pi-key"
          tone="info"
          :loading="loading"
        />
        <StatCard
          label="My overdue actions"
          :value="data?.kpis.overdue_actions ?? '—'"
          icon="pi pi-exclamation-circle"
          :tone="(data?.kpis.overdue_actions ?? 0) > 0 ? 'danger' : 'success'"
          :hint="(data?.kpis.overdue_actions ?? 0) > 0 ? 'Needs attention now' : 'All caught up'"
          :loading="loading"
        />
      </div>

      <!-- Empty-client nudge: leads I captured but never worked. -->
      <RouterLink
        v-if="data?.my_empty_clients > 0"
        to="/clients"
        class="flex items-center gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm dark:border-amber-500/40 dark:bg-amber-500/10"
      >
        <i class="pi pi-exclamation-triangle text-amber-600 dark:text-amber-400" aria-hidden="true" />
        <span class="text-ink">
          You have <span class="font-semibold">{{ data.my_empty_clients }}</span>
          empty {{ data.my_empty_clients === 1 ? 'client' : 'clients' }} — captured but not yet
          worked. Follow up so nothing goes cold.
        </span>
        <i class="pi pi-chevron-right ml-auto text-mute" aria-hidden="true" />
      </RouterLink>

      <!-- This month — my own activity. -->
      <SectionCard :title="`This month · ${data?.month_stats?.label ?? ''}`" icon="pi pi-chart-bar">
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
          <StatCard
            v-for="tile in monthTiles"
            :key="tile.label"
            :label="tile.label"
            :value="tile.value ?? '—'"
            :icon="tile.icon"
            :tone="tile.tone"
            :loading="loading"
          />
        </div>
      </SectionCard>

      <!-- My upcoming — personal, per type, soonest first. -->
      <SectionCard v-if="hasUpcoming" title="My upcoming (7 days)" icon="pi pi-calendar">
        <WorkItemGroups :groups="myUpcoming" empty-text="Nothing planned." />
      </SectionCard>

      <!-- My open & overdue actions — mine only, never other users', per type. -->
      <SectionCard title="My open &amp; overdue actions" icon="pi pi-exclamation-circle">
        <EmptyState
          v-if="!loading && !hasOverdue"
          icon="pi pi-check-circle"
          title="All caught up"
          body="Nothing of yours is past its due date."
        />
        <WorkItemGroups v-else :groups="myOverdue" empty-text="Nothing overdue." />
      </SectionCard>
    </div>
  </div>
</template>
