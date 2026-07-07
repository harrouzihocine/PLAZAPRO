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
import { useNativePhone } from '@/composables/useNativeMode'
import { confirmAction } from '@/composables/useConfirm'
import { useAutoFilter } from '@/composables/useAutoFilter'
import { useDynamicList } from '@/composables/useDynamicList'
import { useWilayas, useCommunes } from '@/composables/useGeography'
import { GTM_PRIORITIES } from '@/features/inventory/api'
import GtmPriorityBadge from '@/features/inventory/components/GtmPriorityBadge.vue'
import SaleStatusBadge from '@/features/inventory/components/SaleStatusBadge.vue'
import { useLocationsStore } from '@/features/inventory/locationsStore'
import { useUnitsStore } from '@/features/inventory/unitsStore'
import { useAuthStore } from '@/features/settings/store'
import { formatMoney } from '@/features/payments/money'

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
  roomNumbers.value.map((r) => ({ value: r.id, label: r.label })),
)
const floorOptions = computed(() => floors.value.map((f) => ({ value: f.id, label: f.label })))
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
const statusOptions = [
  { value: 'available', label: 'Available' },
  { value: 'interested', label: 'Interested' },
  { value: 'reserved', label: 'Reserved' },
  { value: 'sold', label: 'Sold' },
]
// GTM priority filter options (shared source of truth).
const priorityOptions = GTM_PRIORITIES

const showAdvanced = ref(false)

onMounted(() => {
  // Projects (for the name lookup + filter) and units are independent reads —
  // load them in parallel rather than blocking the table on the project list.
  if (!locations.items.length) locations.fetch()
  units.fetch()
})

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
  block: '',
  stack_floor: '',
  position: '',
  gtm_priority: 'medium',
})
const mode = ref(null) // 'edit' | 'correct' | null
const editingId = ref(null)
const correction = reactive({ price: '', sale_status: '', reason: '' })

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
  correction.price = u.price
  correction.sale_status = u.sale_status
  correction.reason = ''
  editingId.value = u.id
  mode.value = 'correct'
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
      price: num(correction.price),
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
      title: `Cancel unit "${u.reference}"?`,
      text: 'The record is kept but marked cancelled. Only available units (not interested, reserved, or sold) can be cancelled.',
      confirmText: 'Cancel unit',
      danger: true,
    })
  ) {
    units.cancel(u.id)
  }
}
</script>

<template>
  <div>
    <PageHeader title="Units" subtitle="All apartments and lots across projects." />

    <SectionCard flush class="mb-5">
      <div class="flex flex-wrap items-end gap-2 px-4 py-3 sm:px-5">
        <BaseSelect
          v-model="units.filters.location_id"
          placeholder="All projects"
          aria-label="Filter by project"
          class="w-full sm:w-44"
          :options="projectOptions"
        />
        <BaseMultiSelect
          v-model="units.filters.room_number_id"
          placeholder="Rooms"
          class="w-full sm:w-36"
          :options="roomNumberOptions"
        />
        <BaseMultiSelect
          v-model="units.filters.floor_id"
          placeholder="Floor"
          class="w-full sm:w-36"
          :options="floorOptions"
        />
        <BaseMultiSelect
          v-model="units.filters.sale_status"
          placeholder="Status"
          class="w-full sm:w-36"
          :options="statusOptions"
        />
        <BaseMultiSelect
          v-model="units.filters.priority"
          placeholder="Priority"
          class="w-full sm:w-36"
          :options="priorityOptions"
        />
        <Button
          :label="showAdvanced ? 'Less filters' : 'More filters'"
          :icon="showAdvanced ? 'pi pi-chevron-up' : 'pi pi-sliders-h'"
          text
          size="small"
          severity="secondary"
          @click="showAdvanced = !showAdvanced"
        />
        <span class="ml-auto flex gap-2">
          <Button
            icon="pi pi-filter-slash"
            text
            severity="secondary"
            aria-label="Reset filters"
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
          label="Wilaya"
          :options="wilayaOptions"
        />
        <BaseMultiSelect
          v-model="units.filters.commune_id"
          label="Commune"
          :options="communeOptions"
          placeholder="Select a wilaya first"
        />
        <BaseInput v-model="units.filters.min_area" label="Min area (m²)" type="number" />
        <BaseInput v-model="units.filters.max_area" label="Max area (m²)" type="number" />
        <MoneyInput v-model="units.filters.min_price" label="Min price" />
        <MoneyInput v-model="units.filters.max_price" label="Max price" />
      </div>
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
        empty-title="No units match"
        empty-body="Loosen the filters to see more inventory."
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
          <p class="num mt-1.5 text-sm text-ink">
            <span class="font-semibold">{{ formatMoney(item.price) }}</span>
            <span class="text-mute">
              <template v-if="item.room_number"> · {{ item.room_number }}</template>
              <template v-if="item.floor"> · {{ item.floor }}</template>
              <template v-if="item.area_sqm"> · {{ item.area_sqm }} m²</template>
            </span>
          </p>
          <p v-if="item.gtm_priority" class="mt-1.5">
            <GtmPriorityBadge :priority="item.gtm_priority" />
          </p>
        </template>
      </NativeList>

      <DataTable
        v-else
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
            title="No units match"
            body="Loosen the filters to see more inventory."
          />
        </template>

        <Column header="Reference">
          <template #body="{ data }">
            <span class="font-medium text-ink">{{ data.reference }}</span>
          </template>
        </Column>
        <Column header="Wilaya">
          <template #body="{ data }">
            {{ data.location?.wilaya || '—' }}
            <span v-if="data.location?.commune" class="text-mute">
              · {{ data.location.commune }}</span
            >
          </template>
        </Column>
        <Column header="Project">
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
        <Column header="Project type">
          <template #body="{ data }">{{ data.location?.type || '—' }}</template>
        </Column>
        <Column header="Rooms">
          <template #body="{ data }">{{ data.room_number || '—' }}</template>
        </Column>
        <Column header="Floor">
          <template #body="{ data }">{{ data.floor || '—' }}</template>
        </Column>
        <Column header="Area">
          <template #body="{ data }">
            <span class="num">{{ data.area_sqm ? `${data.area_sqm} m²` : '—' }}</span>
          </template>
        </Column>
        <Column header="Price">
          <template #body="{ data }">
            <span class="num">{{ formatMoney(data.price) }}</span>
          </template>
        </Column>
        <Column header="Status">
          <template #body="{ data }">
            <SaleStatusBadge :status="data.sale_status" :interested-count="data.interested_count" />
          </template>
        </Column>
        <Column header="Priority">
          <template #body="{ data }">
            <GtmPriorityBadge v-if="data.gtm_priority" :priority="data.gtm_priority" />
            <span v-else class="text-mute">—</span>
          </template>
        </Column>
        <Column v-if="canManage" header="" class="w-28">
          <template #body="{ data }">
            <span class="flex justify-end gap-1" @click.stop>
              <Button
                icon="pi pi-pencil"
                text
                rounded
                size="small"
                severity="secondary"
                aria-label="Edit unit"
                @click="openEdit(data)"
              />
              <Button
                icon="pi pi-history"
                text
                rounded
                size="small"
                severity="secondary"
                aria-label="Correct price / status"
                @click="openCorrect(data)"
              />
              <Button
                icon="pi pi-ban"
                text
                rounded
                size="small"
                severity="danger"
                aria-label="Cancel unit"
                @click="removeUnit(data)"
              />
            </span>
          </template>
        </Column>
      </DataTable>
    </SectionCard>

    <!-- Edit unit specs -->
    <BaseModal
      v-if="mode === 'edit' && canManage"
      title="Edit unit"
      size="max-w-3xl"
      @close="mode = null"
    >
      <form class="space-y-4" @submit.prevent="submitEdit">
        <div class="grid gap-3 sm:grid-cols-3">
          <BaseInput v-model="form.reference" label="Reference" required />
          <BaseSelect
            v-model="form.room_number_id"
            label="Room number"
            placeholder="— none —"
            :options="roomNumbers.map((r) => ({ value: r.id, label: r.label }))"
          />
          <BaseSelect
            v-model="form.floor_id"
            label="Floor"
            placeholder="— none —"
            :options="floors.map((f) => ({ value: f.id, label: f.label }))"
          />
          <BaseInput v-model="form.area_sqm" label="Area (m²)" type="number" />
          <BaseInput v-model="form.block" label="Block" />
          <BaseInput v-model="form.stack_floor" label="Stack floor" type="number" />
          <BaseInput v-model="form.position" label="Position" type="number" />
          <BaseSelect
            v-model="form.gtm_priority"
            label="GTM priority"
            :clearable="false"
            :options="GTM_PRIORITIES"
          />
        </div>
        <p class="text-xs text-mute">
          To change price or sale status, use “Correct” (keeps the old version).
        </p>
        <div class="flex gap-2">
          <Button type="submit" label="Save" icon="pi pi-check" :loading="units.saving" />
          <Button type="button" label="Cancel" severity="secondary" outlined @click="mode = null" />
        </div>
      </form>
    </BaseModal>

    <!-- Correction (price / sale status) via versioning -->
    <BaseModal
      v-if="mode === 'correct' && canManage"
      title="Correct price / status"
      size="max-w-2xl"
      @close="mode = null"
    >
      <form class="space-y-4" @submit.prevent="submitCorrection">
        <div class="grid gap-3 sm:grid-cols-3">
          <MoneyInput v-model="correction.price" label="Price" />
          <BaseSelect
            v-model="correction.sale_status"
            label="Sale status"
            :clearable="false"
            :options="[
              { value: 'available', label: 'Available' },
              { value: 'interested', label: 'Interested' },
              { value: 'sold', label: 'Sold' },
            ]"
          />
          <BaseInput v-model="correction.reason" label="Reason" required />
        </div>
        <p class="text-xs text-mute">
          This cancels the current row and creates a linked new version — the old value is kept.
        </p>
        <div class="flex gap-2">
          <Button
            type="submit"
            label="Apply correction"
            icon="pi pi-check"
            :loading="units.saving"
          />
          <Button type="button" label="Cancel" severity="secondary" outlined @click="mode = null" />
        </div>
      </form>
    </BaseModal>
  </div>
</template>
