<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseModal from '@/components/base/BaseModal.vue'
import BaseMultiSelect from '@/components/base/BaseMultiSelect.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import MoneyInput from '@/components/base/MoneyInput.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import NativeList from '@/components/ui/NativeList.vue'
import FilterPanel from '@/components/ui/FilterPanel.vue'
import OfflineStamp from '@/components/ui/OfflineStamp.vue'
import { useNativePhone } from '@/composables/useNativeMode'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { confirmAction } from '@/composables/useConfirm'
import { t } from '@/i18n'
import { useAutoFilter } from '@/composables/useAutoFilter'
import { useDynamicList, itemLabel } from '@/composables/useDynamicList'
import { useWilayas, useCommunes } from '@/composables/useGeography'
import { gtmPriorityOptions, unitsApi } from '@/features/inventory/api'
import FinishPrices from '@/features/inventory/components/FinishPrices.vue'
import GtmPriorityBadge from '@/features/inventory/components/GtmPriorityBadge.vue'
import SaleStatusBadge from '@/features/inventory/components/SaleStatusBadge.vue'
import { useLocationsStore } from '@/features/inventory/locationsStore'
import { useUnitsStore } from '@/features/inventory/unitsStore'
import { buildUnitRef, dedupeRef } from '@/features/inventory/unitRef'
import { useUnitBulkTools } from '@/features/inventory/useUnitBulkTools'
import { useAuthStore } from '@/features/settings/store'
import { countActiveFilters, floorLabel, roomsLabel } from '@/utils/format'

const units = useUnitsStore()
const locations = useLocationsStore()
const auth = useAuthStore()
const router = useRouter()
const { items: roomNumbers } = useDynamicList('room_numbers')
const { items: floors } = useDynamicList('floors')
const { wilayas } = useWilayas()
const { load: loadCommunes } = useCommunes()

const canManage = auth.can('units.manage')

// Android-shell phones swap the table for tappable cards (NativeList below);
// manage actions live on the unit page / bigger screens there.
const nativePhone = useNativePhone()

// Map location id -> name so the table shows the project, not a raw FK.
const locationName = computed(() => Object.fromEntries(locations.items.map((l) => [l.id, l.name])))

// { value, label } option lists for the multi-select filters.
const roomNumberOptions = computed(() =>
  roomNumbers.value.map((r) => ({ value: r.id, label: itemLabel(r) })),
)
const floorOptions = computed(() => floors.value.map((f) => ({ value: f.id, label: itemLabel(f) })))
const wilayaOptions = computed(() =>
  wilayas.value.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` })),
)
const projectOptions = computed(() => locations.items.map((l) => ({ value: l.id, label: l.name })))

// Communes cascade from the selected wilaya(s): their union, deduped by id.
const communeItems = ref([])
const communeOptions = computed(() =>
  communeItems.value.map((c) => ({ value: c.id, label: c.name })),
)

async function refreshCommuneOptions() {
  const ids = units.filters.wilaya_id
  if (!ids.length) {
    communeItems.value = []
    return
  }
  const lists = await Promise.all(ids.map((id) => loadCommunes(id)))
  const merged = new Map()
  for (const list of lists) for (const c of list) merged.set(c.id, c)
  communeItems.value = [...merged.values()]
}

// When the wilaya selection changes, refresh commune choices and drop any
// selected commune whose wilaya is no longer chosen — the auto-filter watcher
// below reloads the table.
watch(
  () => units.filters.wilaya_id,
  async () => {
    await refreshCommuneOptions()
    const valid = new Set(communeItems.value.map((c) => c.id))
    units.filters.commune_id = units.filters.commune_id.filter((id) => valid.has(id))
  },
)

// Filters apply themselves as they change — no "Filter" button.
useAutoFilter(
  () => units.filters,
  // A filter change resets to page 1 (results shift, so the old page is moot).
  () => units.applyFilters(),
)

// Server-side paginator: load the requested page from the API.
function onPage(e) {
  units.goToPage({ page: e.page + 1, rows: e.rows })
}

// The filters as query params — what the table shows, for the CSV export.
function currentFilterParams() {
  const params = {}
  for (const [k, v] of Object.entries(units.filters)) {
    if (Array.isArray(v)) {
      if (v.length) params[k] = v
    } else if (v !== '' && v != null) {
      params[k] = v
    }
  }
  return params
}

// Row multi-select cancel + Excel export/template/import (shared with the project tab).
const {
  selected,
  exporting,
  downloadingTemplate,
  importInput,
  cancelSelected,
  exportExcel,
  downloadTemplate,
  pickImportFile,
  onImportFile,
} = useUnitBulkTools(units, currentFilterParams)

// A reload replaces the row objects — drop any stale selection with it.
watch(
  () => units.items,
  () => (selected.value = []),
)
const statusOptions = computed(() => [
  { value: 'available', label: t('status.available') },
  { value: 'interested', label: t('status.interested') },
  { value: 'reserved', label: t('status.reserved') },
  { value: 'sold', label: t('status.sold') },
])
// GTM priority filter options (shared source of truth).
const priorityOptions = computed(() => gtmPriorityOptions())

const showAdvanced = ref(false)
const activeFilterCount = computed(() => countActiveFilters(units.filters))

onMounted(() => {
  // Projects (for the name lookup + filter) and units are independent reads —
  // load them in parallel rather than blocking the table on the project list.
  if (!locations.items.length) locations.fetch()
  units.fetch()
})
// pull-to-refresh (APK)
useRefreshable(() => Promise.all([locations.fetch(), units.fetch()]))

function reset() {
  // The auto-filter watcher picks the change up and refetches.
  units.filters = {
    location_id: '',
    wilaya_id: [],
    commune_id: [],
    room_number_id: [],
    floor_id: [],
    sale_status: [],
    priority: [],
    min_area: '',
    max_area: '',
    min_price: '',
    max_price: '',
  }
}

function openUnit(event) {
  router.push({ name: 'inventory.unit', params: { id: event.data.id } })
}

// Edit specs / correct price+status / cancel — same actions as the per-project
// Units tab (LocationDetailView), reused here so managers don't have to leave
// the cross-project table to fix a unit.
const form = reactive({
  reference: '',
  room_number_id: '',
  floor_id: '',
  area_sqm: '',
  // duplicate mode only — edits correct prices via /correct. At least one of
  // the two finish prices is required (backend + DB enforce it).
  price_semi_fini: '',
  price_fini: '',
  block: '',
  stack_floor: '',
  position: '',
  gtm_priority: 'medium',
})
const mode = ref(null) // 'edit' | 'duplicate' | 'correct' | null
const editingId = ref(null)
const correction = reactive({ price_semi_fini: '', price_fini: '', sale_status: '', reason: '' })

function openEdit(u) {
  Object.assign(form, {
    reference: u.reference,
    room_number_id: u.room_number_id ?? '',
    floor_id: u.floor_id ?? '',
    area_sqm: u.area_sqm ?? '',
    block: u.block ?? '',
    stack_floor: u.stack_floor ?? '',
    position: u.position ?? '',
    gtm_priority: u.gtm_priority ?? 'medium',
  })
  editingId.value = u.id
  mode.value = 'edit'
}

function openCorrect(u) {
  correction.price_semi_fini = u.price_semi_fini
  correction.price_fini = u.price_fini
  correction.sale_status = u.sale_status
  correction.reason = ''
  editingId.value = u.id
  mode.value = 'correct'
}

// ── Duplicate (fast insert) ─────────────────────────────────────────────
// Prefill the form from an existing unit; the reference writes itself from
// the specs (buildUnitRef) and gets a -2/-3 suffix when taken.
const duplicateLocation = ref(null) // the source unit's project ({ id, code, name })
const locationRefs = ref([]) // that project's active references (dedup source)
const refAuto = ref(true) // stops once the user hand-edits the reference
let lastAutoRef = null

async function openDuplicate(u) {
  Object.assign(form, {
    reference: '',
    room_number_id: u.room_number_id ?? '',
    floor_id: u.floor_id ?? '',
    area_sqm: u.area_sqm ?? '',
    price_semi_fini: u.price_semi_fini ?? '',
    price_fini: u.price_fini ?? '',
    block: u.block ?? '',
    stack_floor: u.stack_floor ?? '',
    position: u.position ?? '',
    gtm_priority: u.gtm_priority ?? 'medium',
  })
  editingId.value = null
  duplicateLocation.value =
    u.location ?? locations.items.find((l) => l.id === u.location_id) ?? { id: u.location_id }
  refAuto.value = true
  locationRefs.value = []
  mode.value = 'duplicate'
  applyAutoRef()
  // The dedup counter needs the project's full active list, not this page.
  try {
    locationRefs.value = (await unitsApi.list({ location_id: u.location_id })).map(
      (x) => x.reference,
    )
  } catch {
    /* worst case the server's unique rule catches the clash */
  }
  applyAutoRef()
}

function applyAutoRef() {
  if (mode.value !== 'duplicate' || !refAuto.value) return
  // Base (untranslated) labels so the reference is language-stable.
  const base = buildUnitRef(duplicateLocation.value, {
    roomsLabel: roomNumbers.value.find((r) => r.id === Number(form.room_number_id))?.label,
    floorLabel: floors.value.find((f) => f.id === Number(form.floor_id))?.label,
    block: form.block,
    stackFloor: form.stack_floor,
    position: form.position,
  })
  lastAutoRef = dedupeRef(base, locationRefs.value)
  form.reference = lastAutoRef
}

watch(
  () => [form.room_number_id, form.floor_id, form.block, form.stack_floor, form.position],
  applyAutoRef,
)
// A reference the auto-writer didn't produce means the user typed — hands off.
watch(
  () => form.reference,
  (v) => {
    if (mode.value === 'duplicate' && v !== lastAutoRef) refAuto.value = false
  },
)

async function submitDuplicate() {
  try {
    await units.create(duplicateLocation.value.id, {
      reference: form.reference.trim(),
      room_number_id: form.room_number_id || null,
      floor_id: form.floor_id || null,
      area_sqm: num(form.area_sqm),
      price_semi_fini: num(form.price_semi_fini),
      price_fini: num(form.price_fini),
      block: form.block.trim() || null,
      stack_floor: num(form.stack_floor),
      position: num(form.position),
      gtm_priority: form.gtm_priority,
    })
    mode.value = null
  } catch {
    /* surfaced via units.error */
  }
}

function num(v) {
  return v === '' || v === null ? null : Number(v)
}

async function submitEdit() {
  try {
    await units.update(editingId.value, {
      reference: form.reference.trim(),
      room_number_id: form.room_number_id || null,
      floor_id: form.floor_id || null,
      area_sqm: num(form.area_sqm),
      block: form.block.trim() || null,
      stack_floor: num(form.stack_floor),
      position: num(form.position),
      gtm_priority: form.gtm_priority,
    })
    mode.value = null
  } catch {
    /* surfaced via units.error */
  }
}

async function submitCorrection() {
  try {
    await units.correct(editingId.value, {
      price_semi_fini: num(correction.price_semi_fini),
      price_fini: num(correction.price_fini),
      sale_status: correction.sale_status,
      reason: correction.reason.trim(),
    })
    mode.value = null
  } catch {
    /* surfaced via units.error */
  }
}

async function removeUnit(u) {
  if (
    await confirmAction({
      title: t('inventory.cancelUnitTitle', { ref: u.reference }),
      text: t('inventory.cancelUnitText'),
      confirmText: t('inventory.cancelUnit'),
      danger: true,
    })
  ) {
    units.cancel(u.id)
  }
}
</script>

<template>
  <div>
    <PageHeader :title="$t('nav.units')" :subtitle="$t('inventory.unitsSubtitle')">
      <template v-if="!nativePhone" #actions>
        <Button
          :label="$t('inventory.exportExcel')"
          icon="pi pi-download"
          severity="secondary"
          outlined
          size="small"
          :loading="exporting"
          @click="exportExcel"
        />
        <Button
          v-if="canManage"
          :label="$t('inventory.importTemplate')"
          icon="pi pi-file-excel"
          severity="secondary"
          outlined
          size="small"
          :loading="downloadingTemplate"
          @click="downloadTemplate"
        />
        <Button
          v-if="canManage"
          :label="$t('inventory.importExcel')"
          icon="pi pi-upload"
          severity="secondary"
          outlined
          size="small"
          :loading="units.saving"
          @click="pickImportFile"
        />
        <input
          ref="importInput"
          type="file"
          accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,.csv,text/csv,.txt"
          class="hidden"
          @change="onImportFile"
        />
      </template>
    </PageHeader>
    <OfflineStamp :at="units.offlineAt" />

    <SectionCard flush class="mb-5">
      <FilterPanel :active-count="activeFilterCount">
      <div class="flex flex-wrap items-end gap-2 px-4 py-3 sm:px-5">
        <BaseSelect
          v-model="units.filters.location_id"
:placeholder="$t('inventory.allProjects')"
          :aria-label="$t('inventory.filterByProject')"
          class="w-full sm:w-44"
          :options="projectOptions"
        />
        <BaseMultiSelect
          v-model="units.filters.room_number_id"
:placeholder="$t('inventory.rooms')"
          class="w-full sm:w-36"
          :options="roomNumberOptions"
        />
        <BaseMultiSelect
          v-model="units.filters.floor_id"
:placeholder="$t('inventory.floor')"
          class="w-full sm:w-36"
          :options="floorOptions"
        />
        <BaseMultiSelect
          v-model="units.filters.sale_status"
:placeholder="$t('common.status')"
          class="w-full sm:w-36"
          :options="statusOptions"
        />
        <BaseMultiSelect
          v-model="units.filters.priority"
:placeholder="$t('tasks.priority')"
          class="w-full sm:w-36"
          :options="priorityOptions"
        />
        <Button
          :label="showAdvanced ? $t('inventory.lessFilters') : $t('inventory.moreFilters')"
          :icon="showAdvanced ? 'pi pi-chevron-up' : 'pi pi-sliders-h'"
          text
          size="small"
          severity="secondary"
          @click="showAdvanced = !showAdvanced"
        />
        <span class="ms-auto flex gap-2">
          <Button
            icon="pi pi-filter-slash"
            text
            severity="secondary"
:aria-label="$t('common.resetFilters')"
            @click="reset"
          />
        </span>
      </div>

      <div
        v-if="showAdvanced"
        class="grid gap-3 border-t border-line px-4 py-3 sm:grid-cols-2 sm:px-5 lg:grid-cols-4"
      >
        <BaseMultiSelect
          v-model="units.filters.wilaya_id"
:label="$t('geo.wilaya')"
          :options="wilayaOptions"
        />
        <BaseMultiSelect
          v-model="units.filters.commune_id"
:label="$t('geo.commune')"
          :options="communeOptions"
          :placeholder="$t('inventory.selectWilayaFirst')"
        />
        <BaseInput v-model="units.filters.min_area" :label="$t('inventory.minArea')" type="number" />
        <BaseInput v-model="units.filters.max_area" :label="$t('inventory.maxArea')" type="number" />
        <MoneyInput v-model="units.filters.min_price" :label="$t('inventory.minPrice')" />
        <MoneyInput v-model="units.filters.max_price" :label="$t('inventory.maxPrice')" />
      </div>
      </FilterPanel>
    </SectionCard>

    <SectionCard flush>
      <!-- APK phones: inventory as cards — reference + status up top, the
           numbers that matter (rooms · floor · area · price) underneath. -->
      <NativeList
        v-if="nativePhone"
        :items="units.items"
        :loading="units.loading"
        :rows="units.rows"
        :page="units.page"
        :total="units.total"
        clickable
        empty-icon="pi pi-th-large"
:empty-title="$t('inventory.noUnitsMatch')"
        :empty-body="$t('inventory.loosenFilters')"
        @page="onPage"
        @item-click="(u) => openUnit({ data: u })"
      >
        <template #item="{ item }">
          <div class="flex items-center justify-between gap-2">
            <p class="truncate font-semibold text-ink">{{ item.reference }}</p>
            <SaleStatusBadge :status="item.sale_status" :interested-count="item.interested_count" />
          </div>
          <p class="mt-0.5 truncate text-sm text-mute">
            {{ locationName[item.location_id] ?? '—' }}
            <template v-if="item.location?.wilaya"> · {{ item.location.wilaya }}</template>
          </p>
          <!-- Labeled facts — bare "· 1 · 1 ·" numbers read as noise on a card. -->
          <div class="mt-1.5 text-sm text-ink">
            <FinishPrices :semi-fini="item.price_semi_fini" :fini="item.price_fini" inline />
            <span class="num text-mute">
              <template v-if="roomsLabel(item.room_number)"> · {{ roomsLabel(item.room_number) }}</template>
              <template v-if="floorLabel(item.floor)"> · {{ floorLabel(item.floor) }}</template>
              <template v-if="item.area_sqm"> · {{ item.area_sqm }} m²</template>
            </span>
          </div>
          <p v-if="item.gtm_priority" class="mt-1.5">
            <GtmPriorityBadge :priority="item.gtm_priority" />
          </p>
        </template>
      </NativeList>

      <template v-else>
        <!-- Multi-select toolbar: appears once rows are ticked. -->
        <div
          v-if="selected.length"
          class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-2 sm:px-5"
        >
          <span class="text-sm font-medium text-ink">
            {{ $t('inventory.selectedCount', selected.length) }}
          </span>
          <Button
            :label="$t('inventory.cancelSelected')"
            icon="pi pi-ban"
            severity="danger"
            outlined
            size="small"
            :loading="units.saving"
            @click="cancelSelected"
          />
          <Button
            :label="$t('common.clear')"
            text
            size="small"
            severity="secondary"
            @click="selected = []"
          />
        </div>

        <DataTable
        v-model:selection="selected"
        :value="units.items"
        :loading="units.loading"
        lazy
        paginator
        :rows="units.rows"
        :first="(units.page - 1) * units.rows"
        :total-records="units.total"
        :rows-per-page-options="[25, 50, 100]"
        data-key="id"
        class="cursor-pointer"
        @page="onPage"
        @row-click="openUnit"
      >
        <template #empty>
          <EmptyState
            icon="pi pi-th-large"
:title="$t('inventory.noUnitsMatch')"
            :body="$t('inventory.loosenFilters')"
          />
        </template>

        <Column v-if="canManage" selection-mode="multiple" class="w-10" />
        <Column :header="$t('inventory.reference')">
          <template #body="{ data }">
            <span class="font-medium text-ink">{{ data.reference }}</span>
          </template>
        </Column>
        <Column :header="$t('geo.wilaya')">
          <template #body="{ data }">
            {{ data.location?.wilaya || '—' }}
            <span v-if="data.location?.commune" class="text-mute">
              · {{ data.location.commune }}</span
            >
          </template>
        </Column>
        <Column :header="$t('inventory.project')">
          <template #body="{ data }">
            <RouterLink
              :to="{ name: 'inventory.location', params: { id: data.location_id } }"
              class="text-ink hover:underline"
              @click.stop
            >
              {{ locationName[data.location_id] ?? data.location_id }}
            </RouterLink>
            <span v-if="data.location?.contract_type" class="block text-xs text-mute">
              {{ data.location.contract_type }}
            </span>
          </template>
        </Column>
        <Column :header="$t('inventory.projectType')">
          <template #body="{ data }">{{ data.location?.type || '—' }}</template>
        </Column>
        <Column :header="$t('inventory.rooms')">
          <template #body="{ data }">{{ data.room_number || '—' }}</template>
        </Column>
        <Column :header="$t('inventory.floor')">
          <template #body="{ data }">{{ data.floor || '—' }}</template>
        </Column>
        <Column :header="$t('desire.area')">
          <template #body="{ data }">
            <span class="num">{{ data.area_sqm ? `${data.area_sqm} m²` : '—' }}</span>
          </template>
        </Column>
        <Column :header="$t('inventory.price')">
          <template #body="{ data }">
            <FinishPrices :semi-fini="data.price_semi_fini" :fini="data.price_fini" />
          </template>
        </Column>
        <Column :header="$t('common.status')">
          <template #body="{ data }">
            <SaleStatusBadge :status="data.sale_status" :interested-count="data.interested_count" />
          </template>
        </Column>
        <Column :header="$t('tasks.priority')">
          <template #body="{ data }">
            <GtmPriorityBadge v-if="data.gtm_priority" :priority="data.gtm_priority" />
            <span v-else class="text-mute">—</span>
          </template>
        </Column>
        <Column v-if="canManage" header="" class="w-36">
          <template #body="{ data }">
            <span class="flex justify-end gap-1" @click.stop>
              <Button
                icon="pi pi-pencil"
                text
                rounded
                size="small"
                severity="secondary"
:aria-label="$t('inventory.editUnit')"
                @click="openEdit(data)"
              />
              <Button
                icon="pi pi-history"
                text
                rounded
                size="small"
                severity="secondary"
:aria-label="$t('inventory.correctAria')"
                @click="openCorrect(data)"
              />
              <Button
                icon="pi pi-clone"
                text
                rounded
                size="small"
                severity="secondary"
                :aria-label="$t('inventory.duplicateUnit')"
                @click="openDuplicate(data)"
              />
              <Button
                icon="pi pi-ban"
                text
                rounded
                size="small"
                severity="danger"
:aria-label="$t('inventory.cancelUnit')"
                @click="removeUnit(data)"
              />
            </span>
          </template>
        </Column>
        </DataTable>
      </template>
    </SectionCard>

    <!-- Edit unit specs / duplicate into a new unit (same form, + price) -->
    <BaseModal
      v-if="(mode === 'edit' || mode === 'duplicate') && canManage"
      :title="mode === 'duplicate' ? $t('inventory.duplicateUnit') : $t('inventory.editUnit')"
      size="max-w-3xl"
      @close="mode = null"
    >
      <form
        class="space-y-4"
        @submit.prevent="mode === 'duplicate' ? submitDuplicate() : submitEdit()"
      >
        <div class="grid gap-3 sm:grid-cols-3">
          <BaseInput v-model="form.reference" :label="$t('inventory.reference')" required />
          <BaseSelect
            v-model="form.room_number_id"
:label="$t('inventory.roomNumber')"
            :placeholder="$t('common.none')"
            :options="roomNumbers.map((r) => ({ value: r.id, label: itemLabel(r) }))"
          />
          <BaseSelect
            v-model="form.floor_id"
:label="$t('inventory.floor')"
            :placeholder="$t('common.none')"
            :options="floors.map((f) => ({ value: f.id, label: itemLabel(f) }))"
          />
          <BaseInput v-model="form.area_sqm" :label="$t('inventory.areaSqm')" type="number" />
          <MoneyInput
            v-if="mode === 'duplicate'"
            v-model="form.price_semi_fini"
            :label="$t('inventory.priceSemiFini')"
          />
          <MoneyInput
            v-if="mode === 'duplicate'"
            v-model="form.price_fini"
            :label="$t('inventory.priceFini')"
          />
          <BaseInput v-model="form.block" :label="$t('inventory.block')" />
          <BaseInput v-model="form.stack_floor" :label="$t('inventory.stackFloor')" type="number" />
          <BaseInput v-model="form.position" :label="$t('inventory.position')" type="number" />
          <BaseSelect
            v-model="form.gtm_priority"
:label="$t('inventory.gtmPriority')"
            :clearable="false"
            :options="gtmPriorityOptions()"
          />
        </div>
        <p class="text-xs text-mute">
          {{ mode === 'duplicate' ? $t('inventory.refAutoHint') : $t('inventory.useCorrectHint') }}
          <template v-if="mode === 'duplicate'"> {{ $t('inventory.atLeastOnePrice') }}</template>
        </p>
        <div class="flex gap-2">
          <Button
            type="submit"
            :label="mode === 'duplicate' ? $t('inventory.addUnit') : $t('common.save')"
            icon="pi pi-check"
            :loading="units.saving"
          />
          <Button type="button" :label="$t('common.cancel')" severity="secondary" outlined @click="mode = null" />
        </div>
      </form>
    </BaseModal>

    <!-- Correction (price / sale status) via versioning -->
    <BaseModal
      v-if="mode === 'correct' && canManage"
:title="$t('inventory.correctTitle')"
      size="max-w-2xl"
      @close="mode = null"
    >
      <form class="space-y-4" @submit.prevent="submitCorrection">
        <div class="grid gap-3 sm:grid-cols-3">
          <MoneyInput v-model="correction.price_semi_fini" :label="$t('inventory.priceSemiFini')" />
          <MoneyInput v-model="correction.price_fini" :label="$t('inventory.priceFini')" />
          <BaseSelect
            v-model="correction.sale_status"
:label="$t('inventory.saleStatus')"
            :clearable="false"
            :options="[
              { value: 'available', label: $t('status.available') },
              { value: 'interested', label: $t('status.interested') },
              { value: 'sold', label: $t('status.sold') },
            ]"
          />
          <BaseInput v-model="correction.reason" :label="$t('calls.reason')" required />
        </div>
        <p class="text-xs text-mute">
          {{ $t('inventory.correctHint') }}
        </p>
        <div class="flex gap-2">
          <Button
            type="submit"
:label="$t('inventory.applyCorrection')"
            icon="pi pi-check"
            :loading="units.saving"
          />
          <Button type="button" :label="$t('common.cancel')" severity="secondary" outlined @click="mode = null" />
        </div>
      </form>
    </BaseModal>
  </div>
</template>
