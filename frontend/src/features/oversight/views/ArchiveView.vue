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
import { useDynamicList } from '@/composables/useDynamicList'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { useAuthStore } from '@/features/settings/store'
import { confirmAction, toastSuccess, toastError } from '@/composables/useConfirm'
import { formatMoney } from '@/features/payments/money'
import { formatDateTime, todayInput, countActiveFilters } from '@/utils/format'

const auth = useAuthStore()
const router = useRouter()
const canReactivate = computed(() => auth.can('projects.manage'))

const items = ref([])
const meta = ref({})
const summary = ref({ total: 0, total_value: '0.00', by_reason: [], by_agent: [] })
const loading = ref(true)
const busy = ref(false)
const exporting = ref(false)
const page = ref(1)
const selected = ref([])

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
  { value: '', label: 'Any reason' },
  ...reasons.value.map((r) => ({ value: r.label, label: r.label })),
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
  { value: '', label: 'Any project' },
  ...locations.value.map((l) => ({ value: l.id, label: l.name })),
])
const agentOptions = computed(() => [
  { value: '', label: 'Anyone' },
  ...staff.value.map((u) => ({ value: u.id, label: u.name })),
])
const sortOptions = [
  { value: 'recent', label: 'Newest first' },
  { value: 'oldest', label: 'Oldest first' },
]

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
    const data = await oversightApi.archive({ ...activeParams(), page: page.value })
    items.value = data.items ?? []
    meta.value = data.meta ?? {}
    summary.value = data.summary ?? { total: 0, total_value: '0.00', by_reason: [], by_agent: [] }
  } catch (e) {
    toastError(e.response?.data?.message ?? 'Could not load the archive.')
  } finally {
    loading.value = false
  }
}
onMounted(load)
useRefreshable(load) // pull-to-refresh + reconnect self-heal

function apply() {
  page.value = 1
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
function goToPage(p) {
  page.value = p
  load()
}

function openProject(row) {
  router.push({ name: 'clients.project', params: { id: row.client_id, projectId: row.id } })
}

// ── Selection (current page) ──────────────────────────────────────────
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
    title: `Reactivate ${ids.length} project${ids.length > 1 ? 's' : ''}?`,
    text: 'Each will return to the active pipeline with its payment plan restored.',
    confirmText: 'Reactivate all',
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
  if (failed) toastError(`${failed} of ${ids.length} could not be reactivated.`)
  else toastSuccess(`${ids.length} project${ids.length > 1 ? 's' : ''} reactivated.`)
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
    toastError(e.response?.data?.message ?? 'Could not export the archive.')
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
      title="Archive"
      subtitle="Lost & closed deals kept for the record. Reactivate the recoverable ones; the rest stay as searchable history."
    >
      <template #actions>
        <Button
          :label="exporting ? 'Exporting…' : 'Export CSV'"
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
        <p class="text-xs text-mute">Archived deals</p>
        <p class="num mt-0.5 text-2xl font-semibold text-ink">{{ summary.total }}</p>
      </SectionCard>
      <SectionCard flush class="px-4 py-3">
        <p class="text-xs text-mute">Total value archived</p>
        <p class="num mt-0.5 text-2xl font-semibold text-ink">{{ formatMoney(summary.total_value) }}</p>
      </SectionCard>
      <SectionCard flush class="col-span-2 px-4 py-3">
        <p class="mb-1.5 text-xs text-mute">Why deals were lost</p>
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
          <BaseInput v-model="filters.search" label="Search client / phone" @keyup.enter="apply" />
          <BaseSelect v-model="filters.reason" label="Reason" :options="reasonOptions" searchable="auto" />
          <BaseSelect
            v-model="filters.location_id"
            label="Project"
            :options="locationOptions"
            searchable="auto"
          />
          <BaseSelect
            v-model="filters.agent_id"
            label="Opened by"
            :options="agentOptions"
            searchable="auto"
          />
          <BaseInput v-model="filters.from" label="Archived from" type="date" />
          <BaseInput v-model="filters.to" label="Archived to" type="date" />
          <BaseInput v-model="filters.min_price" label="Min price" type="number" />
          <BaseInput v-model="filters.max_price" label="Max price" type="number" />
          <BaseSelect v-model="filters.sort" label="Sort" :options="sortOptions" />
        </div>
        <div class="mt-2 flex items-center gap-2">
          <Button label="Apply" icon="pi pi-filter" size="small" @click="apply" />
          <Button
            v-if="hasFilters"
            label="Clear"
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
          label="Reactivate selected"
          icon="pi pi-undo"
          size="small"
          :loading="busy"
          @click="reactivateSelected"
        />
      </div>

      <p v-if="loading" class="py-8 text-center text-sm text-mute">Loading…</p>
      <EmptyState
        v-else-if="!items.length"
        icon="pi pi-inbox"
        title="No archived deals match these filters"
        body="When a deal is archived as lost or closed, it lands here."
      />

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs text-mute">
              <th v-if="canReactivate" class="w-8 px-4 py-2.5 sm:px-5">
                <input
                  type="checkbox"
                  :checked="allSelected"
                  aria-label="Select all on this page"
                  @change="toggleAll"
                />
              </th>
              <th class="py-2.5 pr-3 font-medium" :class="canReactivate ? '' : 'px-4 sm:px-5'">
                Client
              </th>
              <th class="py-2.5 pr-3 font-medium">Project</th>
              <th class="py-2.5 pr-3 font-medium">Price</th>
              <th class="py-2.5 pr-3 font-medium">Reason</th>
              <th class="py-2.5 pr-3 font-medium">Opened by</th>
              <th class="py-2.5 pr-3 font-medium">Archived</th>
              <th class="py-2.5 pr-3 font-medium">Age</th>
              <th class="py-2.5 pr-4"></th>
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
                class="py-2.5 pr-3 font-medium text-ink"
                :class="canReactivate ? '' : 'px-4 sm:px-5'"
              >
                {{ row.client ?? '—' }}
              </td>
              <td class="py-2.5 pr-3 text-mute">
                <span v-if="row.unit" class="num text-ink">{{ row.unit }}</span>
                <span v-if="row.location"> · {{ row.location }}</span>
                <span v-if="!row.unit && !row.location">—</span>
              </td>
              <td class="num py-2.5 pr-3 text-ink">{{ row.price ? formatMoney(row.price) : '—' }}</td>
              <td class="py-2.5 pr-3 text-mute">{{ row.reason ?? '—' }}</td>
              <td class="py-2.5 pr-3 text-mute">{{ row.opened_by ?? '—' }}</td>
              <td class="num py-2.5 pr-3 whitespace-nowrap text-mute">
                {{ formatDateTime(row.archived_at) }}
              </td>
              <td class="num py-2.5 pr-3 text-mute">{{ ageLabel(row.age_days) }}</td>
              <td class="py-1 pr-4 text-right" @click.stop>
                <Button
                  v-if="canReactivate"
                  label="Reactivate"
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

        <!-- Pagination -->
        <div
          v-if="meta.last_page > 1"
          class="flex items-center justify-between border-t border-line px-4 py-3 text-sm sm:px-5"
        >
          <Button
            label="Previous"
            icon="pi pi-chevron-left"
            text
            size="small"
            :disabled="page <= 1"
            @click="goToPage(page - 1)"
          />
          <span class="num text-mute">Page {{ meta.current_page }} / {{ meta.last_page }}</span>
          <Button
            label="Next"
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

    <ReactivateHandoffModal
      v-if="handoffRow"
      :project="handoffRow"
      @close="handoffRow = null"
      @done="onHandoffDone"
    />
  </div>
</template>
