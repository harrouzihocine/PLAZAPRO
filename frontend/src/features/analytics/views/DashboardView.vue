<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import Tag from 'primevue/tag'
import { analyticsApi } from '@/features/analytics/api'
import { formatMoney } from '@/features/payments/money'
import { useAuthStore } from '@/features/settings/store'
import { formatDateTime, humanize } from '@/utils/format'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import StatCard from '@/components/ui/StatCard.vue'
import StatusTag from '@/components/ui/StatusTag.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

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

const scopeLabel = computed(() => (data.value?.scope === 'agent' ? 'Your book' : 'Company-wide'))

// Pipeline funnel in its natural order. Bars are one hue (magnitude of one
// measure); stage identity lives in the labeled tag, never in bar colour.
const STAGE_ORDER = ['lead', 'negotiating', 'reserved', 'won', 'lost']
const stages = computed(() => {
  const byStage = data.value?.deals_by_stage ?? {}
  const rows = STAGE_ORDER.filter((s) => s in byStage).map((s) => ({
    stage: s,
    count: byStage[s] ?? 0,
  }))
  const max = Math.max(1, ...rows.map((r) => r.count))
  return rows.map((r) => ({ ...r, pct: Math.round((r.count / max) * 100) }))
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
      <template #subtitle>
        Here's what's happening across
        <span class="font-medium text-ink">{{ scopeLabel.toLowerCase() }}</span> today.
      </template>
      <template #badges>
        <Tag
          v-if="data"
          :value="scopeLabel"
          :icon="data.scope === 'agent' ? 'pi pi-user' : 'pi pi-globe'"
          severity="secondary"
        />
      </template>
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
      <!-- KPI row -->
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          label="Active clients"
          :value="data?.kpis.clients ?? '—'"
          icon="pi pi-users"
          :loading="loading"
        />
        <StatCard
          label="Upcoming visits"
          :value="data?.kpis.upcoming_visits ?? '—'"
          icon="pi pi-calendar"
          tone="info"
          :loading="loading"
        />
        <StatCard
          label="Overdue actions"
          :value="data?.kpis.overdue_actions ?? '—'"
          icon="pi pi-exclamation-circle"
          :tone="(data?.kpis.overdue_actions ?? 0) > 0 ? 'danger' : 'success'"
          :hint="(data?.kpis.overdue_actions ?? 0) > 0 ? 'Needs attention now' : 'All caught up'"
          :loading="loading"
        />
        <StatCard
          label="Payments due"
          :value="data?.kpis.payments_due.count ?? '—'"
          icon="pi pi-wallet"
          tone="warning"
          :hint="data ? formatMoney(data.kpis.payments_due.amount) : null"
          :loading="loading"
        />
      </div>

      <div class="grid grid-cols-1 gap-5 xl:grid-cols-5">
        <!-- Pipeline funnel -->
        <SectionCard title="Deals by stage" icon="pi pi-filter" class="xl:col-span-2">
          <EmptyState
            v-if="!loading && stages.every((s) => s.count === 0)"
            icon="pi pi-filter"
            title="No deals yet"
            body="Deals appear here as clients move through the pipeline."
          />
          <ol v-else class="space-y-3.5">
            <li v-for="row in stages" :key="row.stage" class="flex items-center gap-3">
              <span class="w-32 shrink-0">
                <StatusTag :value="row.stage" />
              </span>
              <span
                class="relative h-6 flex-1 overflow-hidden rounded bg-surface-100 dark:bg-surface-800"
              >
                <span
                  class="absolute inset-y-0 left-0 rounded bg-primary-600 transition-[width] duration-500 dark:bg-primary-400"
                  :style="{ width: row.count > 0 ? `max(${row.pct}%, 6px)` : '0' }"
                  aria-hidden="true"
                />
              </span>
              <span class="num w-8 shrink-0 text-right text-sm font-semibold text-ink">
                {{ row.count }}
              </span>
            </li>
          </ol>
        </SectionCard>

        <!-- Next visits -->
        <SectionCard title="Next visits" icon="pi pi-calendar" flush class="xl:col-span-3">
          <EmptyState
            v-if="!loading && !data?.upcoming_visits?.length"
            icon="pi pi-calendar"
            title="Nothing scheduled"
            body="Scheduled visits will show up here."
          />
          <ul v-else class="divide-y divide-line">
            <li
              v-for="v in data?.upcoming_visits ?? []"
              :key="v.id"
              class="flex items-center gap-3 px-4 py-3 sm:px-5"
            >
              <span
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300"
              >
                <i
                  :class="v.type === 'in_site' ? 'pi pi-map-marker' : 'pi pi-building'"
                  aria-hidden="true"
                />
              </span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-medium text-ink">
                  {{ v.client ?? '—' }}
                </span>
                <span class="block truncate text-xs text-mute">
                  {{ humanize(v.type) }}<template v-if="v.unit"> · {{ v.unit }}</template>
                </span>
              </span>
              <span class="num shrink-0 text-xs text-mute">
                {{ formatDateTime(v.scheduled_at) }}
              </span>
            </li>
          </ul>
        </SectionCard>
      </div>

      <!-- Overdue actions -->
      <SectionCard title="Overdue actions" icon="pi pi-exclamation-circle" flush>
        <EmptyState
          v-if="!loading && !data?.overdue_actions?.length"
          icon="pi pi-check-circle"
          title="All caught up"
          body="No next action is past its due date."
        />
        <ul v-else class="divide-y divide-line">
          <li
            v-for="a in data?.overdue_actions ?? []"
            :key="a.id"
            class="flex items-center gap-3 px-4 py-3 sm:px-5"
          >
            <span
              class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300"
            >
              <i class="pi pi-bell" aria-hidden="true" />
            </span>
            <span class="min-w-0 flex-1">
              <span class="block truncate text-sm font-medium text-ink">
                {{ humanize(a.type) }}
              </span>
              <span class="block truncate text-xs text-mute">{{ a.assigned_to ?? '—' }}</span>
            </span>
            <span class="num shrink-0 text-xs font-medium text-danger">
              {{ formatDateTime(a.due_at) }}
            </span>
          </li>
        </ul>
      </SectionCard>

      <p v-if="data?.scope === 'agent'" class="text-center text-xs text-mute">
        Showing your assigned clients and actions only.
        <RouterLink to="/clients" class="text-primary-600 hover:underline dark:text-primary-400">
          Open your client list →
        </RouterLink>
      </p>
    </div>
  </div>
</template>
