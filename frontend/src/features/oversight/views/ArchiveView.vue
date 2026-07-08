<script setup>
// The Archive desk (oversight.archive): the company-wide list of archived
// (lost/closed) client projects, so a manager can review them in one place,
// filter, reactivate the recoverable ones, and export for reporting. Parked
// desires are excluded server-side — this is the "dead deals" ledger.
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import FilterPanel from '@/components/ui/FilterPanel.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ReactivateHandoffModal from '@/features/oversight/components/ReactivateHandoffModal.vue'
import { oversightApi } from '@/features/oversight/api'
import { projectsApi, staffApi } from '@/features/clients/api'
import { locationsApi } from '@/features/inventory/api'
import { useDynamicList, itemLabel } from '@/composables/useDynamicList'
import { useInfiniteScroll } from '@/composables/useInfiniteScroll'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { useAuthStore } from '@/features/settings/store'
import { confirmAction, toastSuccess, toastError } from '@/composables/useConfirm'
import { formatMoney } from '@/features/payments/money'
import { formatDateTime, todayInput, countActiveFilters } from '@/utils/format'
import { t } from '@/i18n'

const auth = useAuthStore()
const router = useRouter()
const canReactivate = computed(() => auth.can('projects.manage'))

// The archive holds years of history (thousands of rows) — it lazy-loads in
// pages of 50, appended as the manager scrolls, instead of one giant table.
const PER_PAGE = 50

const items = ref([])
const meta = ref({})
const summary = ref({ total: 0, total_value: '0.00', by_reason: [], by_agent: [] })
const loading = ref(true)
const loadingMore = ref(false)
const busy = ref(false)
const exporting = ref(false)
const selected = ref([])

const hasMore = computed(() => (meta.value.current_page ?? 1) < (meta.value.last_page ?? 1))

const filters = reactive({
  search: '',
  reason: '',
  location_id: '',
  agent_id: '',
  from: '',
  to: '',
  min_price: '',
  max_price: '',
  sort: 'recent',
})

// Filter pickers.
const { items: reasons } = useDynamicList('archive_reasons')
const reasonOptions = computed(() => [
  { value: '', label: t('archive.anyReason') },
  ...reasons.value.map((r) => ({ value: r.label, label: itemLabel(r) })),
])

const locations = ref([])
const staff = ref([])
onMounted(async () => {
  try {
    locations.value = await locationsApi.list()
  } catch {
    /* the location filter is best-effort */
  }
  try {
    staff.value = await staffApi.list()
  } catch {
    /* the opened-by filter is best-effort */
  }
})
const locationOptions = computed(() => [
  { value: '', label: t('archive.anyProject') },
  ...locations.value.map((l) => ({ value: l.id, label: l.name })),
])
const agentOptions = computed(() => [
  { value: '', label: t('archive.anyone') },
  ...staff.value.map((u) => ({ value: u.id, label: u.name })),
])
const sortOptions = computed(() => [
  { value: 'recent', label: t('archive.newestFirst') },
  { value: 'oldest', label: t('archive.oldestFirst') },
])

const activeFilterCount = computed(() => countActiveFilters(filters, ['sort']))
const hasFilters = computed(() =>
  Object.entries(filters).some(([k, v]) => k !== 'sort' && v !== '' && v !== null),
)

// Only non-empty filters travel to the server — keeps the query clean.
function activeParams() {
  const params = {}
  for (const [k, v] of Object.entries(filters)) {
    if (v !== '' && v !== null) params[k] = v
  }
  return params
}

async function load() {
  loading.value = true
  selected.value = []
  try {
    const data = await oversightApi.archive({ ...activeParams(), page: 1, per_page: PER_PAGE })
    items.value = data.items ?? []
    meta.value = data.meta ?? {}
    summary.value = data.summary ?? { total: 0, total_value: '0.00', by_reason: [], by_agent: [] }
  } catch (e) {
    toastError(e.response?.data?.message ?? t('archive.loadFailed'))
  } finally {
    loading.value = false
  }
}
onMounted(load)
useRefreshable(load) // pull-to-refresh + reconnect self-heal

async function loadMore() {
  if (loading.value || loadingMore.value || !hasMore.value) return
  loadingMore.value = true
  try {
    const data = await oversightApi.archive({
      ...activeParams(),
      page: (meta.value.current_page ?? 1) + 1,
      per_page: PER_PAGE,
    })
    // Reactivations shift the pages under us — append only rows we don't
    // already show, so a boundary drift never duplicates one.
    const seen = new Set(items.value.map((i) => i.id))
    items.value.push(...(data.items ?? []).filter((i) => !seen.has(i.id)))
    meta.value = data.meta ?? {}
  } catch (e) {
    toastError(e.response?.data?.message ?? t('archive.loadMoreFailed'))
  } finally {
    loadingMore.value = false
  }
}

// Scrolling near the table's end pulls the next page in; the button below the
// table stays as the explicit / no-IntersectionObserver fallback.
const { sentinel } = useInfiniteScroll(loadMore)

function apply() {
  load()
}
function clear() {
  Object.assign(filters, {
    search: '',
    reason: '',
    location_id: '',
    agent_id: '',
    from: '',
    to: '',
    min_price: '',
    max_price: '',
    sort: 'recent',
  })
  apply()
}

function openProject(row) {
  router.push({ name: 'clients.project', params: { id: row.client_id, projectId: row.id } })
}

// ── Selection (all loaded rows) ───────────────────────────────────────
const allSelected = computed(
  () => items.value.length > 0 && selected.value.length === items.value.length,
)
function toggleAll() {
  selected.value = allSelected.value ? [] : items.value.map((i) => i.id)
}
function toggleOne(id) {
  const i = selected.value.indexOf(id)
  if (i === -1) selected.value.push(id)
  else selected.value.splice(i, 1)
}

// ── Reactivate ────────────────────────────────────────────────────────
// Per-row reactivate opens the hand-off modal (choose who handles it, as-is or
// as a separate project); bulk reactivate below stays a plain reactivate.
const handoffRow = ref(null)

function openHandoff(row) {
  handoffRow.value = row
}
async function onHandoffDone() {
  handoffRow.value = null
  await load()
}

async function reactivateSelected() {
  const ids = [...selected.value]
  const ok = await confirmAction({
    title: t('archive.reactivateNTitle', { n: ids.length }),
    text: t('archive.reactivateNText'),
    confirmText: t('archive.reactivateAll'),
  })
  if (!ok) return
  busy.value = true
  let failed = 0
  for (const id of ids) {
    try {
      await projectsApi.reactivate(id)
    } catch {
      failed++
    }
  }
  busy.value = false
  if (failed) toastError(t('archive.reactivateFailedCount', { failed, total: ids.length }))
  else toastSuccess(t('archive.reactivatedCount', { n: ids.length }))
  await load()
}

// ── Export ────────────────────────────────────────────────────────────
async function exportCsv() {
  exporting.value = true
  try {
    const blob = await oversightApi.exportArchive(activeParams())
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `archive-${todayInput()}.csv`
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  } catch (e) {
    toastError(e.response?.data?.message ?? t('archive.exportFailed'))
  } finally {
    exporting.value = false
  }
}

function ageLabel(days) {
  if (days === null || days === undefined) return '—'
  if (days === 0) return 'today'
  return `${days}d`
}
</script>

<template>
  <div>
    <PageHeader
:title="$t('nav.archive')"
      :subtitle="$t('archive.subtitle')"
    >
      <template #actions>
        <Button
          :label="exporting ? $t('archive.exporting') : $t('archive.exportCsv')"
          icon="pi pi-download"
          severity="secondary"
          outlined
          :loading="exporting"
          @click="exportCsv"
        />
      </template>
    </PageHeader>

    <!-- Summary strip -->
    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
      <SectionCard flush class="px-4 py-3">
        <p class="text-xs text-mute">{{ $t('archive.archivedDeals') }}</p>
        <p class="num mt-0.5 text-2xl font-semibold text-ink">{{ summary.total }}</p>
      </SectionCard>
      <SectionCard flush class="px-4 py-3">
        <p class="text-xs text-mute">{{ $t('archive.totalValue') }}</p>
        <p class="num mt-0.5 text-2xl font-semibold text-ink">{{ formatMoney(summary.total_value) }}</p>
      </SectionCard>
      <SectionCard flush class="col-span-2 px-4 py-3">
        <p class="mb-1.5 text-xs text-mute">{{ $t('archive.whyLost') }}</p>
        <div v-if="summary.by_reason?.length" class="flex flex-wrap gap-1.5">
          <span
            v-for="r in summary.by_reason"
            :key="r.label"
            class="rounded-full bg-highlight px-2.5 py-1 text-xs text-ink"
          >
            {{ r.label }} · <span class="num font-semibold">{{ r.count }}</span>
          </span>
        </div>
        <p v-else class="text-xs text-mute">—</p>
      </SectionCard>
    </div>

    <SectionCard flush>
      <!-- Filters -->
      <FilterPanel :active-count="activeFilterCount">
      <div class="border-b border-line px-4 py-3 sm:px-5">
        <div class="grid grid-cols-2 items-end gap-2 sm:grid-cols-3 lg:grid-cols-4">
          <BaseInput v-model="filters.search" :label="$t('archive.searchClientPhone')" @keyup.enter="apply" />
          <BaseSelect v-model="filters.reason" :label="$t('calls.reason')" :options="reasonOptions" searchable="auto" />
          <BaseSelect
            v-model="filters.location_id"
:label="$t('inventory.project')"
            :options="locationOptions"
            searchable="auto"
          />
          <BaseSelect
            v-model="filters.agent_id"
:label="$t('project.openedBy')"
            :options="agentOptions"
            searchable="auto"
          />
          <BaseInput v-model="filters.from" :label="$t('archive.archivedFrom')" type="date" />
          <BaseInput v-model="filters.to" :label="$t('archive.archivedTo')" type="date" />
          <BaseInput v-model="filters.min_price" :label="$t('inventory.minPrice')" type="number" />
          <BaseInput v-model="filters.max_price" :label="$t('inventory.maxPrice')" type="number" />
          <BaseSelect v-model="filters.sort" :label="$t('archive.sort')" :options="sortOptions" />
        </div>
        <div class="mt-2 flex items-center gap-2">
          <Button :label="$t('common.apply')" icon="pi pi-filter" size="small" @click="apply" />
          <Button
            v-if="hasFilters"
:label="$t('common.clear')"
            size="small"
            text
            severity="secondary"
            @click="clear"
          />
        </div>
      </div>
      </FilterPanel>

      <!-- Bulk action bar -->
      <div
        v-if="selected.length && canReactivate"
        class="flex items-center justify-between border-b border-line bg-highlight px-4 py-2 text-sm sm:px-5"
      >
        <span class="text-ink">{{ selected.length }} selected</span>
        <Button
:label="$t('archive.reactivateSelected')"
          icon="pi pi-undo"
          size="small"
          :loading="busy"
          @click="reactivateSelected"
        />
      </div>

      <p v-if="loading" class="py-8 text-center text-sm text-mute">{{ $t('common.loading') }}</p>
      <EmptyState
        v-else-if="!items.length"
        icon="pi pi-inbox"
:title="$t('archive.emptyTitle')"
        :body="$t('archive.emptyBody')"
      />

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-start text-xs text-mute">
              <th v-if="canReactivate" class="w-8 px-4 py-2.5 sm:px-5">
                <input
                  type="checkbox"
                  :checked="allSelected"
:aria-label="$t('archive.selectAllLoaded')"
                  @change="toggleAll"
                />
              </th>
              <th class="py-2.5 pe-3 font-medium" :class="canReactivate ? '' : 'px-4 sm:px-5'">
                Client
              </th>
              <th class="py-2.5 pe-3 font-medium">{{ $t('inventory.project') }}</th>
              <th class="py-2.5 pe-3 font-medium">{{ $t('inventory.price') }}</th>
              <th class="py-2.5 pe-3 font-medium">{{ $t('calls.reason') }}</th>
              <th class="py-2.5 pe-3 font-medium">{{ $t('project.openedBy') }}</th>
              <th class="py-2.5 pe-3 font-medium">{{ $t('status.archived') }}</th>
              <th class="py-2.5 pe-3 font-medium">{{ $t('archive.age') }}</th>
              <th class="py-2.5 pe-4"></th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in items"
              :key="row.id"
              class="cursor-pointer border-t border-line hover:bg-highlight"
              @click="openProject(row)"
            >
              <td v-if="canReactivate" class="px-4 py-2.5 sm:px-5" @click.stop>
                <input
                  type="checkbox"
                  :checked="selected.includes(row.id)"
                  :aria-label="`Select ${row.client}`"
                  @change="toggleOne(row.id)"
                />
              </td>
              <td
                class="py-2.5 pe-3 font-medium text-ink"
                :class="canReactivate ? '' : 'px-4 sm:px-5'"
              >
                {{ row.client ?? '—' }}
              </td>
              <td class="py-2.5 pe-3 text-mute">
                <span v-if="row.unit" class="num text-ink">{{ row.unit }}</span>
                <span v-if="row.location"> · {{ row.location }}</span>
                <span v-if="!row.unit && !row.location">—</span>
              </td>
              <td class="num py-2.5 pe-3 text-ink">{{ row.price ? formatMoney(row.price) : '—' }}</td>
              <td class="py-2.5 pe-3 text-mute">{{ row.reason ?? '—' }}</td>
              <td class="py-2.5 pe-3 text-mute">{{ row.opened_by ?? '—' }}</td>
              <td class="num py-2.5 pe-3 whitespace-nowrap text-mute">
                {{ formatDateTime(row.archived_at) }}
              </td>
              <td class="num py-2.5 pe-3 text-mute">{{ ageLabel(row.age_days) }}</td>
              <td class="py-1 pe-4 text-end" @click.stop>
                <Button
                  v-if="canReactivate"
:label="$t('project.reactivate')"
                  icon="pi pi-undo"
                  text
                  size="small"
                  :disabled="busy"
                  @click="openHandoff(row)"
                />
              </td>
            </tr>
          </tbody>
        </table>

        <!-- Lazy-load footer: the sentinel pre-fetches the next page as it
             nears the viewport; the button is the explicit fallback. -->
        <div
          ref="sentinel"
          class="flex flex-col items-center gap-2 border-t border-line px-4 py-3 text-sm sm:px-5"
        >
          <span class="num text-xs text-mute">Showing {{ items.length }} of {{ meta.total ?? items.length }}</span>
          <Button
            v-if="hasMore"
:label="$t('matches.loadMore')"
            icon="pi pi-arrow-down"
            size="small"
            severity="secondary"
            outlined
            :loading="loadingMore"
            @click="loadMore"
          />
        </div>
      </div>
    </SectionCard>

    <ReactivateHandoffModal
      v-if="handoffRow"
      :project="handoffRow"
      @close="handoffRow = null"
      @done="onHandoffDone"
    />
  </div>
</template>
