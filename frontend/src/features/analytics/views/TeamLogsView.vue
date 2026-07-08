<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import Button from 'primevue/button'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import StatCard from '@/components/ui/StatCard.vue'
import FilterPanel from '@/components/ui/FilterPanel.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { analyticsApi } from '@/features/analytics/api'
import { staffApi } from '@/features/clients/api'
import { useAutoFilter } from '@/composables/useAutoFilter'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { useAuthStore } from '@/features/settings/store'
import { formatDateTime, todayInput } from '@/utils/format'
import { t } from '@/i18n'

const KIND = {
  call: { label: 'Call', icon: 'pi pi-phone' },
  office_visit: { label: 'Office visit', icon: 'pi pi-building' },
  in_site_visit: { label: 'In-site visit', icon: 'pi pi-map-marker' },
}

const auth = useAuthStore()
// Only logs.view_all sees other users; everyone else is pinned to their own logs
// (the server enforces this too — here it just drives the selector, the User column
// and the page copy so the view never over-promises a company-wide feed).
const canViewAll = computed(() => auth.can('logs.view_all'))
const pageTitle = computed(() => (canViewAll.value ? t('nav.teamLogs') : t('nav.myLogs')))
const pageSubtitle = computed(() =>
  canViewAll.value
    ? t('teamLogs.subtitleAll')
    : t('teamLogs.subtitleOwn'),
)

// Default view = the agent's plan: upcoming work (scheduled visits + pending
// next-actions) from today onward, soonest first. `from` uses the LOCAL date
// helper (Africa/Algiers rule — never toISOString). The Show switch flips to
// logged (or both merged), and clearing filters reaches any past range.
const DEFAULTS = { user_id: '', type: '', mode: 'upcoming', from: todayInput(), to: '' }
const filters = ref({ ...DEFAULTS })
// Badge counts what differs from the DEFAULT view (a stock "today + planned"
// prefill isn't a user-applied filter; mode is a presentation switch).
const activeFilterCount = computed(
  () =>
    Object.keys(DEFAULTS).filter((k) => k !== 'mode' && filters.value[k] !== DEFAULTS[k]).length,
)
const page = ref(1)
const items = ref([])
const summary = ref({})
const meta = ref({})
const loading = ref(true)
const loadError = ref(false)
const staff = ref([])

const userOptions = computed(() => [
  { value: '', label: t('oversight.allUsers') },
  ...staff.value.map((u) => ({ value: u.id, label: u.name })),
])
const typeOptions = computed(() => [
  { value: '', label: t('teamLogs.allTypes') },
  { value: 'call', label: t('pipeline.tabCalls') },
  { value: 'office_visit', label: t('pipeline.tabOfficeVisits') },
  { value: 'in_site_visit', label: t('pipeline.tabInSiteVisits') },
])
const modeOptions = computed(() => [
  { value: 'all', label: t('teamLogs.modeAll') },
  { value: 'logged', label: t('teamLogs.modeLogged') },
  { value: 'upcoming', label: t('teamLogs.modeUpcoming') },
])

const summaryTiles = computed(() => [
  { label: t('pipeline.tabCalls'), value: summary.value.calls ?? 0, icon: KIND.call.icon, tone: 'info' },
  { label: t('pipeline.tabOfficeVisits'), value: summary.value.office_visits ?? 0, icon: KIND.office_visit.icon, tone: 'default' },
  { label: t('pipeline.tabInSiteVisits'), value: summary.value.in_site_visits ?? 0, icon: KIND.in_site_visit.icon, tone: 'default' },
  { label: t('status.won'), value: summary.value.won ?? 0, icon: 'pi pi-check-circle', tone: 'success' },
  { label: t('status.lost'), value: summary.value.lost ?? 0, icon: 'pi pi-times-circle', tone: 'danger' },
])

// Stale-response guard: filters auto-apply, so a slow older answer must never
// overwrite a newer one (same ticket pattern as the store-backed pages).
let fetchTicket = 0

async function fetch() {
  loading.value = true
  const ticket = ++fetchTicket
  try {
    const res = await analyticsApi.teamLogs({ ...filters.value, page: page.value })
    if (ticket !== fetchTicket) return
    items.value = res.items
    summary.value = res.summary
    meta.value = res.meta
    loadError.value = false
  } catch (e) {
    // A failed load must never masquerade as "Nothing planned" — show the
    // error state (with Retry) instead of the empty state. Rethrow so the
    // global recovery layer still toasts server errors.
    if (ticket === fetchTicket) loadError.value = true
    throw e
  } finally {
    if (ticket === fetchTicket) loading.value = false
  }
}

function goToPage(p) {
  page.value = p
  fetch()
}

useRefreshable(() => fetch()) // pull-to-refresh (APK)

onMounted(async () => {
  fetch()
  if (!canViewAll.value) return // no user selector to populate for self-scoped logs
  try {
    staff.value = await staffApi.list()
  } catch {
    /* the user dropdown is best-effort; the number of users is small */
  }
})

// Filters apply themselves; any change resets to the first page.
useAutoFilter(
  () => filters.value,
  () => {
    page.value = 1
    fetch()
  },
)
</script>

<template>
  <div>
    <PageHeader :title="pageTitle" :subtitle="pageSubtitle" />

    <!-- Per-type scorecard for the current filters. -->
    <div class="mb-5 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
      <StatCard
        v-for="tile in summaryTiles"
        :key="tile.label"
        :label="tile.label"
        :value="tile.value"
        :icon="tile.icon"
        :tone="tile.tone"
        :loading="loading"
      />
    </div>

    <SectionCard flush>
      <!-- Filters -->
      <FilterPanel :active-count="activeFilterCount">
      <div class="grid grid-cols-2 items-end gap-2 border-b border-line px-4 py-3 sm:grid-cols-3 sm:px-5 lg:grid-cols-5">
        <BaseSelect
          v-if="canViewAll"
          v-model="filters.user_id"
:label="$t('oversight.user')"
          :options="userOptions"
          searchable="auto"
        />
        <BaseSelect v-model="filters.type" :label="$t('inventory.type')" :options="typeOptions" />
        <BaseSelect v-model="filters.mode" :label="$t('teamLogs.show')" :options="modeOptions" />
        <BaseInput v-model="filters.from" :label="$t('oversight.from')" type="date" />
        <BaseInput v-model="filters.to" :label="$t('oversight.to')" type="date" />
      </div>
      </FilterPanel>

      <p v-if="loading" class="py-8 text-center text-sm text-mute">Loading…</p>
      <EmptyState
        v-else-if="loadError"
        icon="pi pi-exclamation-triangle"
:title="$t('teamLogs.loadFailed')"
        :body="$t('teamLogs.loadFailedBody')"
      >
        <Button :label="$t('common.retry')" icon="pi pi-refresh" size="small" outlined @click="fetch" />
      </EmptyState>
      <EmptyState
        v-else-if="!items.length"
        icon="pi pi-list-check"
        :title="
          filters.mode === 'upcoming'
            ? $t('teamLogs.emptyPlanned')
            : filters.mode === 'all'
              ? $t('teamLogs.emptyAll')
              : $t('teamLogs.emptyLogged')
        "
      />

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-start text-xs text-mute">
              <th class="px-4 py-2.5 font-medium sm:px-5">When</th>
              <th v-if="canViewAll" class="py-2.5 pe-3 font-medium">User</th>
              <th class="py-2.5 pe-3 font-medium">Type</th>
              <th class="py-2.5 pe-3 font-medium">Client</th>
              <th class="py-2.5 pe-4 font-medium">Detail</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in items"
              :key="row.id"
              class="border-t border-line transition-colors hover:bg-surface-50 dark:hover:bg-surface-900"
            >
              <td class="num whitespace-nowrap px-4 py-2.5 sm:px-5">{{ formatDateTime(row.at) }}</td>
              <td v-if="canViewAll" class="py-2.5 pe-3">{{ row.user ?? '—' }}</td>
              <td class="py-2.5 pe-3">
                <span class="inline-flex items-center gap-1.5">
                  <i :class="(KIND[row.kind] ?? {}).icon ?? 'pi pi-circle'" class="text-mute" aria-hidden="true" />
                  {{ (KIND[row.kind] ?? {}).label ?? row.kind }}
                  <!-- Only meaningful in the merged view — in "Upcoming" every row is planned. -->
                  <span
                    v-if="row.planned && filters.mode === 'all'"
                    class="rounded-full bg-highlight px-1.5 py-0.5 text-[10px] font-semibold text-primary-700 dark:text-primary-300"
                  >
                    Planned
                  </span>
                </span>
              </td>
              <td class="py-2.5 pe-3">
                <RouterLink
                  v-if="row.link"
                  :to="row.link"
                  class="text-primary-600 hover:underline dark:text-primary-400"
                >
                  {{ row.client ?? '—' }}
                </RouterLink>
                <span v-else>{{ row.client ?? '—' }}</span>
              </td>
              <td class="py-2.5 pe-4 text-mute">{{ row.detail ?? '—' }}</td>
            </tr>
          </tbody>
        </table>

        <!-- Pagination -->
        <div
          v-if="meta.last_page > 1"
          class="flex items-center justify-between border-t border-line px-4 py-3 text-sm sm:px-5"
        >
          <Button
:label="$t('common.back')"
            icon="pi pi-chevron-left"
            text
            size="small"
            :disabled="page <= 1"
            @click="goToPage(page - 1)"
          />
          <span class="num text-mute">Page {{ meta.current_page }} / {{ meta.last_page }}</span>
          <Button
:label="$t('common.next')"
            icon="pi pi-chevron-right"
            icon-pos="right"
            text
            size="small"
            :disabled="page >= meta.last_page"
            @click="goToPage(page + 1)"
          />
        </div>
      </div>
    </SectionCard>
  </div>
</template>
