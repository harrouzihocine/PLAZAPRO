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
import UpcomingTasksCard from '@/features/pipeline/components/UpcomingTasksCard.vue'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { cacheSnapshot, serveSnapshot } from '@/features/offline/snapshots'
import OfflineStamp from '@/components/ui/OfflineStamp.vue'
import { isNativeApp } from '@/utils/nativeApp'
import { t } from '@/i18n'

const auth = useAuthStore()

// The app pins the user's own next tasks above everything else — opening the
// app answers "what do I do now" in one glance. Web keeps its dashboard as-is.
const isNative = isNativeApp()

const data = ref(null)
const loading = ref(true)
const denied = ref(false)
const error = ref('')

const greeting = computed(() => {
  const h = new Date().getHours()
  if (h < 12) return t('dashboard.goodMorning')
  if (h < 18) return t('dashboard.goodAfternoon')
  return t('dashboard.goodEvening')
})

const firstName = computed(() => (auth.user?.name ?? '').split(' ')[0] || '')

// Icon per work kind — shared by "My upcoming" and "My overdue".
const KIND_ICON = {
  call: 'pi pi-phone',
  office_visit: 'pi pi-building',
  in_site_visit: 'pi pi-map-marker',
  task: 'pi pi-check-square',
}

// Shared per-type breakdown — calls, office visits, in-site visits and tasks
// are never mixed together. Feeds both "My upcoming" and "My open & overdue".
const workGroups = () => [
  { key: 'calls', label: t('pipeline.tabCalls'), icon: KIND_ICON.call },
  { key: 'office_visits', label: t('pipeline.tabOfficeVisits'), icon: KIND_ICON.office_visit },
  { key: 'in_site_visits', label: t('pipeline.tabInSiteVisits'), icon: KIND_ICON.in_site_visit },
  { key: 'tasks', label: t('nav.tasks'), icon: KIND_ICON.task },
]
const myUpcoming = computed(() =>
  workGroups().map((g) => ({ ...g, items: data.value?.my_upcoming?.[g.key] ?? [] })),
)
const hasUpcoming = computed(() => myUpcoming.value.some((g) => g.items.length))

const myOverdue = computed(() =>
  workGroups().map((g) => ({ ...g, items: data.value?.my_overdue?.[g.key] ?? [] })),
)
const hasOverdue = computed(() => myOverdue.value.some((g) => g.items.length))

// This month's own performance — rapports logged per type + deals I closed.
const monthTiles = computed(() => {
  const s = data.value?.month_stats ?? {}
  return [
    { label: t('pipeline.tabCalls'), value: s.calls, icon: KIND_ICON.call, tone: 'info' },
    { label: t('pipeline.tabOfficeVisits'), value: s.office_visits, icon: KIND_ICON.office_visit, tone: 'default' },
    { label: t('pipeline.tabInSiteVisits'), value: s.in_site_visits, icon: KIND_ICON.in_site_visit, tone: 'default' },
    { label: t('status.won'), value: s.won, icon: 'pi pi-check-circle', tone: 'success' },
    { label: t('status.lost'), value: s.lost, icon: 'pi pi-times-circle', tone: 'danger' },
  ]
})

const offlineAt = ref(null)

async function load() {
  try {
    data.value = await analyticsApi.dashboard()
    error.value = ''
    offlineAt.value = null
    cacheSnapshot('dashboard', data.value)
  } catch (e) {
    const served = await serveSnapshot(e, 'dashboard', (snap, at) => {
      data.value = snap
      offlineAt.value = at
    })
    if (!served) {
      if (e.response?.status === 403) denied.value = true
      else error.value = t('dashboard.loadFailed')
    }
  } finally {
    loading.value = false
  }
}
onMounted(load)
useRefreshable(load) // pull-to-refresh (APK)
</script>

<template>
  <div>
    <PageHeader :title="firstName ? `${greeting}, ${firstName}` : greeting">
      <template #subtitle>{{ $t('dashboard.subtitle') }}</template>
    </PageHeader>
    <OfflineStamp :at="offlineAt" />

    <!-- Android app: the "do this next" fast lane, first thing on open. -->
    <UpcomingTasksCard v-if="isNative" class="mb-5" />

    <SectionCard v-if="denied">
      <EmptyState
        icon="pi pi-lock"
:title="$t('dashboard.noAccessTitle')"
        :body="$t('dashboard.noAccessBody')"
      />
    </SectionCard>

    <SectionCard v-else-if="error">
      <EmptyState icon="pi pi-exclamation-triangle" :title="error" />
    </SectionCard>

    <div v-else class="space-y-5">
      <!-- KPI row — personal to me -->
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
:label="$t('dashboard.myActiveClients')"
          :value="data?.kpis.clients ?? '—'"
          icon="pi pi-users"
          :loading="loading"
        />
        <StatCard
:label="$t('dashboard.myActiveProjects')"
          :value="data?.kpis.active_projects ?? '—'"
          icon="pi pi-sitemap"
          tone="info"
          :loading="loading"
        />
        <StatCard
:label="$t('dashboard.myOpenDeals')"
          :value="data?.kpis.open_deals ?? '—'"
          icon="pi pi-key"
          tone="info"
          :loading="loading"
        />
        <StatCard
:label="$t('dashboard.myOverdueActions')"
          :value="data?.kpis.overdue_actions ?? '—'"
          icon="pi pi-exclamation-circle"
          :tone="(data?.kpis.overdue_actions ?? 0) > 0 ? 'danger' : 'success'"
          :hint="(data?.kpis.overdue_actions ?? 0) > 0 ? $t('dashboard.needsAttention') : $t('dashboard.allCaughtUp')"
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
        <i class="pi pi-chevron-right ms-auto text-mute" aria-hidden="true" />
      </RouterLink>

      <!-- This month — my own activity. -->
      <SectionCard :title="`${$t('dashboard.thisMonth')} · ${data?.month_stats?.label ?? ''}`" icon="pi pi-chart-bar">
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
      <SectionCard v-if="hasUpcoming" :title="$t('dashboard.myUpcoming')" icon="pi pi-calendar">
        <WorkItemGroups :groups="myUpcoming" empty-text="Nothing planned." />
      </SectionCard>

      <!-- My open & overdue actions — mine only, never other users', per type. -->
      <SectionCard :title="$t('dashboard.myOpenOverdue')" icon="pi pi-exclamation-circle">
        <EmptyState
          v-if="!loading && !hasOverdue"
          icon="pi pi-check-circle"
:title="$t('dashboard.allCaughtUp')"
          body="Nothing of yours is past its due date."
        />
        <WorkItemGroups v-else :groups="myOverdue" empty-text="Nothing overdue." />
      </SectionCard>
    </div>
  </div>
</template>
