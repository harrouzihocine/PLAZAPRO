<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import Chart from 'primevue/chart'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import Skeleton from 'primevue/skeleton'
import SectionCard from '@/components/ui/SectionCard.vue'
import StatCard from '@/components/ui/StatCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import SaleStatusBadge from '@/features/inventory/components/SaleStatusBadge.vue'
import { analyticsApi } from '@/features/analytics/api'
import { formatMoney } from '@/features/payments/money'
import { formatDate } from '@/utils/format'

// The "Voice of Client" panel — reused on the Location page (whole development)
// and on a Unit page (drill-down). It reads BuildLocationFeedback and renders the
// funnel, objections/topics Pareto, sentiment, lost reasons, demand leaderboard,
// activity trend, verbatims and rule-based recommendations. Manager-gated by the
// tab that mounts it (reports.view).
const props = defineProps({
  scope: { type: String, required: true }, // 'location' | 'unit'
  id: { type: [String, Number], required: true },
})

const data = ref(null)
const loading = ref(false)
const error = ref(false)
const range = ref('90') // '30' | '90' | 'all'

const RANGES = [
  { value: '30', label: '30 days' },
  { value: '90', label: '90 days' },
  { value: 'all', label: 'All time' },
]

function windowParams() {
  if (range.value === 'all') return {}
  const from = new Date()
  from.setDate(from.getDate() - Number(range.value))
  const fmt = (d) => d.toISOString().slice(0, 10)
  return { from: fmt(from), to: fmt(new Date()) }
}

async function load() {
  loading.value = true
  error.value = false
  try {
    const params = windowParams()
    data.value =
      props.scope === 'unit'
        ? await analyticsApi.unitFeedback(props.id, params)
        : await analyticsApi.locationFeedback(props.id, params)
  } catch {
    error.value = true
    data.value = null
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(range, load)

const isLocation = computed(() => data.value?.scope === 'location')

// The engagement funnel as KPI tiles — calls/office only apply to the development
// (a call has no unit), so the unit drill-down trims them.
const funnelStages = computed(() => {
  const f = data.value?.funnel ?? {}
  const all = [
    { key: 'calls', label: 'Calls', value: f.calls, icon: 'pi pi-phone', locationOnly: true },
    { key: 'office_visits', label: 'Office visits', value: f.office_visits, icon: 'pi pi-building', locationOnly: true },
    { key: 'in_site_visits', label: 'Site visits', value: f.in_site_visits, icon: 'pi pi-map-marker' },
    { key: 'shortlisted', label: 'Shortlisted', value: f.shortlisted, icon: 'pi pi-star', tone: 'info' },
    { key: 'interested', label: 'Interested', value: f.interested, icon: 'pi pi-heart', tone: 'success' },
    { key: 'reserved', label: 'Reserved', value: f.reserved, icon: 'pi pi-lock', tone: 'warning' },
    { key: 'won', label: 'Won', value: f.won, icon: 'pi pi-trophy', tone: 'success' },
  ]
  return all.filter((s) => isLocation.value || !s.locationOnly)
})

// Headline conversion: of everything shortlisted, how much sold.
const conversion = computed(() => {
  const f = data.value?.funnel
  if (!f || !f.shortlisted) return null
  return Math.round((f.won / f.shortlisted) * 100)
})

const RECO_TONES = {
  high: 'border-danger/40 bg-danger/5',
  medium: 'border-warning/40 bg-warning/5',
  info: 'border-info/40 bg-info/5',
}
const RECO_ICONS = { high: 'pi pi-exclamation-triangle text-danger', medium: 'pi pi-lightbulb text-warning', info: 'pi pi-info-circle text-info' }

const VERBATIM_KINDS = {
  call: { label: 'Call', icon: 'pi pi-phone' },
  office_visit: { label: 'Office visit', icon: 'pi pi-building' },
  in_site_visit: { label: 'Site visit', icon: 'pi pi-map-marker' },
}

// ── Charts ────────────────────────────────────────────────────────────────
// Chart.js needs concrete colors; resolve the app's theme tokens fresh each build
// so light/dark are honoured (they flip with [data-theme]).
function tokens() {
  const s = getComputedStyle(document.documentElement)
  const v = (name, fallback) => s.getPropertyValue(name).trim() || fallback
  return {
    primary: v('--p-primary-500', '#c8a24a'),
    info: v('--app-info', '#3b82f6'),
    success: v('--app-success', '#16a34a'),
    warning: v('--app-warning', '#d97706'),
    danger: v('--app-danger', '#dc2626'),
    ink: v('--p-text-color', '#1f2937'),
    mute: v('--p-text-muted-color', '#6b7280'),
    line: v('--p-content-border-color', '#e5e7eb'),
    card: v('--p-content-background', '#ffffff'),
  }
}

// A ranked list → horizontal bar (magnitude by identity). Single series, so no
// legend — the card title names it (dataviz rule).
function rankedBar(rows, color) {
  if (!rows?.length) return null
  const c = tokens()
  return {
    data: {
      labels: rows.map((r) => r.label),
      datasets: [
        {
          data: rows.map((r) => r.count),
          backgroundColor: c[color],
          borderRadius: 4,
          borderSkipped: false,
          barThickness: 16,
          maxBarThickness: 22,
        },
      ],
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { beginAtZero: true, ticks: { color: c.mute, precision: 0 }, grid: { color: c.line } },
        y: { ticks: { color: c.ink }, grid: { display: false } },
      },
    },
  }
}

const objectionsChart = computed(() => rankedBar(data.value?.objections, 'warning'))
const topicsChart = computed(() => rankedBar(data.value?.topics, 'primary'))
const lostChart = computed(() => rankedBar(data.value?.lost_reasons, 'danger'))

// Sentiment — polarity, so status colors WITH labels (never colour alone).
const sentimentChart = computed(() => {
  const s = data.value?.sentiment
  if (!s) return null
  const total = s.positive + s.negative + s.neutral
  if (!total) return null
  const c = tokens()
  return {
    data: {
      labels: ['Interested', 'Neutral', 'Not interested'],
      datasets: [
        {
          data: [s.positive, s.neutral, s.negative],
          backgroundColor: [c.success, c.mute, c.danger],
          borderColor: c.card,
          borderWidth: 2,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '62%',
      plugins: { legend: { position: 'bottom', labels: { color: c.ink, usePointStyle: true } } },
    },
  }
})

// Activity over time — two series on ONE shared count scale (never dual-axis).
const trendChart = computed(() => {
  const t = data.value?.trend
  if (!t?.length) return null
  const c = tokens()
  const label = (w) => formatDate(w).replace(/,.*/, '')
  const datasets = []
  if (isLocation.value) {
    datasets.push({ label: 'Calls', data: t.map((w) => w.calls), borderColor: c.primary, backgroundColor: c.primary, tension: 0.35, borderWidth: 2, pointRadius: 3 })
  }
  datasets.push({ label: 'Visits', data: t.map((w) => w.visits), borderColor: c.info, backgroundColor: c.info, tension: 0.35, borderWidth: 2, pointRadius: 3 })
  return {
    data: { labels: t.map((w) => label(w.week)), datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom', labels: { color: c.ink, usePointStyle: true } } },
      scales: {
        x: { ticks: { color: c.mute }, grid: { display: false } },
        y: { beginAtZero: true, ticks: { color: c.mute, precision: 0 }, grid: { color: c.line } },
      },
    },
  }
})

const hasAnyData = computed(() => {
  const f = data.value?.funnel
  return f && (f.calls || f.in_site_visits || f.office_visits || f.shortlisted)
})
</script>

<template>
  <div>
    <!-- Date-range filter — one row above the charts (dataviz interaction rule). -->
    <div class="mb-4 flex flex-wrap items-center gap-1.5">
      <button
        v-for="r in RANGES"
        :key="r.value"
        type="button"
        class="rounded-full border px-3 py-1.5 text-xs transition-colors"
        :class="
          range === r.value
            ? 'border-primary bg-highlight font-medium text-ink'
            : 'border-line text-mute hover:border-primary hover:text-ink'
        "
        @click="range = r.value"
      >
        {{ r.label }}
      </button>
    </div>

    <div v-if="loading" class="space-y-4">
      <Skeleton height="6rem" />
      <Skeleton height="16rem" />
    </div>

    <EmptyState
      v-else-if="error"
      icon="pi pi-exclamation-circle"
      title="Couldn't load feedback"
      body="The Voice-of-Client analytics failed to load. Try again."
    />

    <EmptyState
      v-else-if="!hasAnyData"
      icon="pi pi-comments"
      title="No client feedback yet"
      body="Once agents log calls and visits (with objections and outcomes) here, this page turns them into insights."
    />

    <div v-else-if="data" class="space-y-5">
      <!-- Recommended actions — the "what to do next". -->
      <SectionCard
        v-if="data.recommendations.length"
        title="Recommended actions"
        icon="pi pi-compass"
      >
        <ul class="grid gap-3 sm:grid-cols-2">
          <li
            v-for="(rec, i) in data.recommendations"
            :key="i"
            class="rounded-xl border p-3.5"
            :class="RECO_TONES[rec.severity] ?? RECO_TONES.info"
          >
            <p class="flex items-center gap-2 text-sm font-semibold text-ink">
              <i :class="RECO_ICONS[rec.severity] ?? RECO_ICONS.info" aria-hidden="true" />
              {{ rec.title }}
            </p>
            <p class="mt-1 text-sm text-mute">{{ rec.body }}</p>
          </li>
        </ul>
      </SectionCard>

      <!-- Engagement funnel. -->
      <SectionCard title="Engagement funnel" icon="pi pi-filter">
        <template #actions>
          <span v-if="conversion !== null" class="text-xs text-mute">
            <span class="font-semibold text-ink">{{ conversion }}%</span> shortlisted → won
          </span>
        </template>
        <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
          <StatCard
            v-for="s in funnelStages"
            :key="s.key"
            :label="s.label"
            :value="s.value"
            :icon="s.icon"
            :tone="s.tone ?? 'default'"
          />
        </div>
      </SectionCard>

      <!-- Voice of Customer: objections + sentiment. -->
      <div class="grid gap-5 lg:grid-cols-2">
        <SectionCard title="Top objections" icon="pi pi-exclamation-circle">
          <div v-if="objectionsChart" class="h-64">
            <Chart type="bar" :data="objectionsChart.data" :options="objectionsChart.options" class="h-full" />
          </div>
          <EmptyState
            v-else
            icon="pi pi-check-circle"
            title="No objections logged"
            body="Ask agents to tick client concerns on calls and visits."
          />
        </SectionCard>

        <SectionCard title="Client sentiment" icon="pi pi-heart">
          <div v-if="sentimentChart" class="h-64">
            <Chart type="doughnut" :data="sentimentChart.data" :options="sentimentChart.options" class="h-full" />
          </div>
          <EmptyState v-else icon="pi pi-heart" title="No visit outcomes yet" />
        </SectionCard>
      </div>

      <!-- Discussion topics + why-lost. -->
      <div class="grid gap-5 lg:grid-cols-2">
        <SectionCard title="Discussion topics" icon="pi pi-comments">
          <div v-if="topicsChart" class="h-64">
            <Chart type="bar" :data="topicsChart.data" :options="topicsChart.options" class="h-full" />
          </div>
          <EmptyState v-else icon="pi pi-comments" title="Nothing logged yet" />
        </SectionCard>

        <SectionCard v-if="isLocation" title="Why deals were lost" icon="pi pi-times-circle">
          <div v-if="lostChart" class="h-64">
            <Chart type="bar" :data="lostChart.data" :options="lostChart.options" class="h-full" />
          </div>
          <EmptyState v-else icon="pi pi-check-circle" title="No lost deals in this window" />
        </SectionCard>
      </div>

      <!-- Activity trend over time. -->
      <SectionCard title="Activity trend" icon="pi pi-chart-line">
        <div v-if="trendChart" class="h-64">
          <Chart type="line" :data="trendChart.data" :options="trendChart.options" class="h-full" />
        </div>
        <EmptyState v-else icon="pi pi-chart-line" title="No recent activity" />
      </SectionCard>

      <!-- Most-demanded units leaderboard (development view only). -->
      <SectionCard v-if="isLocation" title="Most demanded units" icon="pi pi-th-large" flush>
        <DataTable
          :value="data.demand_units"
          data-key="id"
          :rows="10"
          :paginator="data.demand_units.length > 10"
          removable-sort
        >
          <template #empty>
            <EmptyState icon="pi pi-th-large" title="No demand signals yet" />
          </template>
          <Column header="Unit">
            <template #body="{ data: u }">
              <RouterLink
                :to="{ name: 'inventory.unit', params: { id: u.id } }"
                class="font-medium text-ink hover:underline"
              >
                {{ u.reference }}
              </RouterLink>
            </template>
          </Column>
          <Column field="price" header="Price">
            <template #body="{ data: u }"><span class="num">{{ formatMoney(u.price) }}</span></template>
          </Column>
          <Column field="demand" header="Demand" sortable>
            <template #body="{ data: u }"><span class="num font-semibold text-ink">{{ u.demand }}</span></template>
          </Column>
          <Column field="visits" header="Visits" sortable>
            <template #body="{ data: u }"><span class="num">{{ u.visits }}</span></template>
          </Column>
          <Column field="interested" header="Interested" sortable>
            <template #body="{ data: u }"><span class="num text-success">{{ u.interested }}</span></template>
          </Column>
          <Column field="not_interested" header="Rejected" sortable>
            <template #body="{ data: u }"><span class="num text-danger">{{ u.not_interested }}</span></template>
          </Column>
          <Column field="won" header="Won" sortable>
            <template #body="{ data: u }"><span class="num">{{ u.won }}</span></template>
          </Column>
          <Column header="Status">
            <template #body="{ data: u }"><SaleStatusBadge :status="u.sale_status" /></template>
          </Column>
        </DataTable>
      </SectionCard>

      <!-- Units seen but rejected — a pricing / finish red flag. -->
      <SectionCard
        v-if="isLocation && data.at_risk_units.length"
        title="Units at risk (seen but rejected)"
        icon="pi pi-flag"
      >
        <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <li
            v-for="u in data.at_risk_units"
            :key="u.id"
            class="rounded-xl border border-danger/30 bg-danger/5 p-3"
          >
            <RouterLink
              :to="{ name: 'inventory.unit', params: { id: u.id } }"
              class="font-medium text-ink hover:underline"
            >
              {{ u.reference }}
            </RouterLink>
            <p class="mt-1 text-xs text-mute">
              {{ u.rejected }}/{{ u.seen }} rejected ·
              <span class="font-semibold text-danger">{{ Math.round(u.rejection_ratio * 100) }}%</span>
              · {{ formatMoney(u.price) }}
            </p>
          </li>
        </ul>
      </SectionCard>

      <!-- Verbatims — the qualitative voice behind the numbers. -->
      <SectionCard v-if="data.verbatims.length" title="Recent client comments" icon="pi pi-quote-right">
        <ul class="space-y-3">
          <li
            v-for="(v, i) in data.verbatims"
            :key="i"
            class="rounded-xl border border-line bg-card p-3.5"
          >
            <p class="whitespace-pre-line text-sm text-ink">“{{ v.note }}”</p>
            <p class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-mute">
              <span>
                <i :class="(VERBATIM_KINDS[v.type] ?? VERBATIM_KINDS.call).icon" aria-hidden="true" />
                {{ (VERBATIM_KINDS[v.type] ?? VERBATIM_KINDS.call).label }}
              </span>
              <span v-if="v.unit">· Unit {{ v.unit }}</span>
              <span v-if="v.client">· {{ v.client }}</span>
              <span v-if="v.agent">· {{ v.agent }}</span>
              <span v-if="v.date">· {{ formatDate(v.date) }}</span>
            </p>
          </li>
        </ul>
      </SectionCard>
    </div>
  </div>
</template>
