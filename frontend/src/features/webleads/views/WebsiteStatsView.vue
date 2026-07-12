<script setup>
import { computed, onMounted, ref } from 'vue'
import Chart from 'primevue/chart'
import SelectButton from 'primevue/selectbutton'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import KpiTile from '@/features/analytics/components/kpi/KpiTile.vue'
import { donut, rankedBar, timeLine } from '@/features/analytics/kpi/charts'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { webStatsApi } from '@/features/webleads/api'
import { t } from '@/i18n'

// The public website's own analytics board (permission web.stats): traffic,
// most-viewed projects/units, contact clicks. Charts reuse the KPI system
// (chartTokens-based helpers) so the whole app reads as one.

const days = ref(30)
const windowOptions = [
  { label: t('webstats.days7'), value: 7 },
  { label: t('webstats.days30'), value: 30 },
  { label: t('webstats.days90'), value: 90 },
]

const data = ref(null)
const loading = ref(true)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await webStatsApi.get(days.value)
  } catch (e) {
    error.value = e.response?.data?.message ?? t('common.actionFailed')
  } finally {
    loading.value = false
  }
}

onMounted(load)
useRefreshable(load)

function setWindow(value) {
  if (!value) return
  days.value = value
  load()
}

const totals = computed(() => data.value?.totals ?? {})
const deltas = computed(() => data.value?.deltas ?? {})
const hasTraffic = computed(() => (data.value?.series ?? []).some((d) => d.page_views > 0))

// Two same-unit series on one axis: unique visitors under total page views.
const trafficChart = computed(() => {
  if (!data.value) return null
  return timeLine([
    {
      label: t('webstats.pageViews'),
      points: data.value.series.map((d) => ({ date: d.date, value: d.page_views })),
      color: 'primary',
    },
    {
      label: t('webstats.visits'),
      points: data.value.series.map((d) => ({ date: d.date, value: d.visits })),
      color: 'info',
    },
  ])
})

const topProjects = computed(() => data.value?.top_projects ?? [])
const topUnits = computed(() => data.value?.top_units ?? [])

const projectsChart = computed(() => {
  if (!topProjects.value.length) return null
  return rankedBar(
    topProjects.value.map((p) => p.name),
    topProjects.value.map((p) => p.views),
  )
})

const devicesChart = computed(() => {
  const d = data.value?.devices
  if (!d || d.mobile + d.desktop === 0) return null
  return donut([t('webstats.mobile'), t('webstats.desktop')], [d.mobile, d.desktop])
})

const LOCALE_LABELS = { fr: 'Français', ar: 'العربية', en: 'English' }
const localesChart = computed(() => {
  const locales = data.value?.locales ?? {}
  const entries = Object.entries(locales)
  if (!entries.length) return null
  return donut(
    entries.map(([code]) => LOCALE_LABELS[code] ?? code),
    entries.map(([, count]) => count),
  )
})
</script>

<template>
  <div>
    <PageHeader :title="$t('webstats.title')" :subtitle="$t('webstats.subtitle')">
      <template #actions>
        <SelectButton
          :model-value="days"
          :options="windowOptions"
          option-label="label"
          option-value="value"
          :allow-empty="false"
          size="small"
          @update:model-value="setWindow"
        />
      </template>
    </PageHeader>

    <SectionCard v-if="error">
      <EmptyState icon="pi pi-exclamation-triangle" :title="error" />
    </SectionCard>

    <div v-else class="space-y-5">
      <!-- Headline numbers -->
      <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <KpiTile
          :label="$t('webstats.visits')" :value="totals.visits" :delta="deltas.visits"
          icon="pi pi-users" tone="default" :loading="loading" :hint="$t('webstats.visitsHint')"
        />
        <KpiTile
          :label="$t('webstats.pageViews')" :value="totals.page_views" :delta="deltas.page_views"
          icon="pi pi-eye" tone="info" :loading="loading"
        />
        <KpiTile
          :label="$t('webstats.projectViews')" :value="totals.project_views"
          icon="pi pi-building" tone="default" :loading="loading"
        />
        <KpiTile
          :label="$t('webstats.unitViews')" :value="totals.unit_views"
          icon="pi pi-th-large" tone="default" :loading="loading"
        />
        <KpiTile
          :label="$t('webstats.leads')" :value="totals.leads" :delta="deltas.leads"
          icon="pi pi-inbox" tone="success" :loading="loading" :hint="$t('webstats.leadsHint')"
        />
        <KpiTile
          :label="$t('webstats.whatsappClicks')" :value="totals.whatsapp_clicks"
          icon="pi pi-whatsapp" tone="success" :loading="loading"
        />
        <KpiTile
          :label="$t('webstats.phoneClicks')" :value="totals.phone_clicks"
          icon="pi pi-phone" tone="info" :loading="loading"
        />
        <KpiTile
          :label="$t('webstats.shares')" :value="(totals.shares ?? 0) + (totals.social_clicks ?? 0)"
          icon="pi pi-share-alt" tone="default" :loading="loading" :hint="$t('webstats.sharesHint')"
        />
      </div>

      <!-- Daily traffic -->
      <SectionCard :title="$t('webstats.traffic')" icon="pi pi-chart-line">
        <div v-if="hasTraffic" class="h-72"><Chart type="line" v-bind="trafficChart" class="h-full" /></div>
        <EmptyState v-else icon="pi pi-chart-line" :title="$t('webstats.noDataYet')" :body="$t('webstats.noDataBody')" />
      </SectionCard>

      <div class="grid gap-5 lg:grid-cols-2">
        <!-- Most-viewed projects -->
        <SectionCard :title="$t('webstats.topProjects')" icon="pi pi-building">
          <template v-if="topProjects.length">
            <div :style="{ height: `${Math.max(topProjects.length * 2.4, 8)}rem` }">
              <Chart type="bar" v-bind="projectsChart" class="h-full" />
            </div>
            <div class="mt-4 overflow-x-auto">
              <table class="w-full text-sm">
                <thead>
                  <tr class="border-b border-line text-start text-xs uppercase tracking-wide text-mute">
                    <th class="py-2 text-start">{{ $t('webstats.project') }}</th>
                    <th class="py-2 text-end">{{ $t('webstats.views') }}</th>
                    <th class="py-2 text-end">{{ $t('webstats.visitors') }}</th>
                    <th class="py-2 text-end">{{ $t('webstats.leads') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="p in topProjects" :key="p.id" class="border-b border-line/60 last:border-0">
                    <td class="py-2 font-medium text-ink">{{ p.name }}</td>
                    <td class="num py-2 text-end text-ink">{{ p.views }}</td>
                    <td class="num py-2 text-end text-mute">{{ p.visitors }}</td>
                    <td class="num py-2 text-end" :class="p.leads ? 'font-semibold text-success' : 'text-mute'">{{ p.leads }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
          <EmptyState v-else icon="pi pi-building" :title="$t('webstats.noDataYet')" />
        </SectionCard>

        <!-- Most-viewed units -->
        <SectionCard :title="$t('webstats.topUnits')" icon="pi pi-th-large">
          <ul v-if="topUnits.length" class="divide-y divide-line/60">
            <li v-for="(u, i) in topUnits" :key="u.id" class="flex items-center gap-3 py-2.5">
              <span class="num w-6 text-center text-sm font-semibold text-mute">{{ i + 1 }}</span>
              <div class="min-w-0 flex-1">
                <p class="truncate font-medium text-ink">{{ u.reference }}</p>
                <p class="truncate text-xs text-mute">{{ u.project }}</p>
              </div>
              <span class="num text-sm font-semibold text-ink">{{ u.views }}</span>
              <span class="text-xs text-mute">{{ $t('webstats.views').toLowerCase() }}</span>
            </li>
          </ul>
          <EmptyState v-else icon="pi pi-th-large" :title="$t('webstats.noDataYet')" />
        </SectionCard>
      </div>

      <div class="grid gap-5 lg:grid-cols-2">
        <SectionCard :title="$t('webstats.devices')" icon="pi pi-mobile">
          <div v-if="devicesChart" class="h-56"><Chart type="doughnut" v-bind="devicesChart" class="h-full" /></div>
          <EmptyState v-else icon="pi pi-mobile" :title="$t('webstats.noDataYet')" />
        </SectionCard>

        <SectionCard :title="$t('webstats.languages')" icon="pi pi-language">
          <div v-if="localesChart" class="h-56"><Chart type="doughnut" v-bind="localesChart" class="h-full" /></div>
          <EmptyState v-else icon="pi pi-language" :title="$t('webstats.noDataYet')" />
        </SectionCard>
      </div>
    </div>
  </div>
</template>
