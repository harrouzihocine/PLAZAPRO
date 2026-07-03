<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import Skeleton from 'primevue/skeleton'
import Tab from 'primevue/tab'
import TabList from 'primevue/tablist'
import TabPanel from 'primevue/tabpanel'
import TabPanels from 'primevue/tabpanels'
import Tabs from 'primevue/tabs'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseModal from '@/components/base/BaseModal.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import StatCard from '@/components/ui/StatCard.vue'
import StatusTag from '@/components/ui/StatusTag.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ActivityTimeline from '@/components/ui/ActivityTimeline.vue'
import { useDynamicList } from '@/composables/useDynamicList'
import { GTM_PRIORITIES, reservationsApi } from '@/features/inventory/api'
import BoxesPanel from '@/features/inventory/components/BoxesPanel.vue'
import GtmPriorityBadge from '@/features/inventory/components/GtmPriorityBadge.vue'
import LocationMap from '@/features/inventory/components/LocationMap.vue'
import MediaGallery from '@/features/inventory/components/MediaGallery.vue'
import SaleStatusBadge from '@/features/inventory/components/SaleStatusBadge.vue'
import StackingPlan from '@/features/inventory/components/StackingPlan.vue'
import { googleMapsUrl } from '@/features/inventory/googleMaps'
import { useLocationsStore } from '@/features/inventory/locationsStore'
import { useUnitsStore } from '@/features/inventory/unitsStore'
import { useAuthStore } from '@/features/settings/store'
import { confirmAction } from '@/composables/useConfirm'
import { formatDate } from '@/utils/format'
import { formatMoney } from '@/features/payments/money'

// One project's workspace, organised in tabs so nothing drowns: Overview
// (facts + map), Stacking plan, Units, Boxes, Media, and the full Activity
// (audit) history the record accumulated.
const props = defineProps({ id: { type: [String, Number], required: true } })
const locations = useLocationsStore()
const units = useUnitsStore()
const auth = useAuthStore()
const { items: unitTypes } = useDynamicList('unit_types')
const { items: floors } = useDynamicList('floors')

const canManage = auth.can('units.manage')
const canReserve = auth.can('units.reserve')

const mapsUrl = computed(() => googleMapsUrl(locations.current ?? {}))

const stackingRef = ref(null)
const reserving = ref(false)

// Sale-status mix across the project's units — the at-a-glance commercial state.
const statusCounts = computed(() => {
  const counts = { available: 0, reserved: 0, sold: 0 }
  for (const u of units.items) {
    if (u.sale_status in counts) counts[u.sale_status] += 1
  }
  return counts
})

// Reservation lifecycle from the stacking-plan cell. Refresh both the plan (for
// the colour + countdown) and the units table (for the sale_status) afterwards.
async function afterHold(fn) {
  reserving.value = true
  try {
    await fn()
    await Promise.all([stackingRef.value?.reload(), units.fetchForLocation(props.id)])
  } catch (e) {
    units.error = e.response?.data?.message ?? 'Action failed.'
  } finally {
    reserving.value = false
  }
}

const reserveUnit = (unit) => afterHold(() => reservationsApi.reserve(unit.id))
const releaseHold = (unit) => afterHold(() => reservationsApi.release(unit.reservation_id))
const convertHold = (unit) => afterHold(() => reservationsApi.convert(unit.reservation_id))

const blank = {
  reference: '',
  type_id: '',
  floor_id: '',
  area_sqm: '',
  price: '',
  block: '',
  stack_floor: '',
  position: '',
  gtm_priority: 'medium',
}
const form = reactive({ ...blank })
const mode = ref(null) // 'create' | 'edit' | 'correct' | null
const editingId = ref(null)
const correction = reactive({ price: '', sale_status: '', reason: '' })

onMounted(async () => {
  await locations.fetchOne(props.id)
  await units.fetchForLocation(props.id)
})

function openCreate() {
  Object.assign(form, blank)
  editingId.value = null
  mode.value = 'create'
}

function openEdit(u) {
  Object.assign(form, {
    reference: u.reference,
    type_id: u.type_id ?? '',
    floor_id: u.floor_id ?? '',
    area_sqm: u.area_sqm ?? '',
    price: u.price ?? '',
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

async function submit() {
  const specs = {
    reference: form.reference.trim(),
    type_id: form.type_id || null,
    floor_id: form.floor_id || null,
    area_sqm: num(form.area_sqm),
    block: form.block.trim() || null,
    stack_floor: num(form.stack_floor),
    position: num(form.position),
    gtm_priority: form.gtm_priority,
  }
  try {
    if (mode.value === 'edit') {
      await units.update(editingId.value, specs)
    } else {
      await units.create(props.id, { ...specs, price: num(form.price) })
    }
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

async function remove(u) {
  if (
    await confirmAction({
      title: `Cancel unit "${u.reference}"?`,
      text: 'The record is kept but marked cancelled.',
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
    <div v-if="!locations.current" class="space-y-4">
      <Skeleton width="18rem" height="2rem" />
      <Skeleton height="12rem" />
    </div>

    <template v-else>
      <PageHeader :title="locations.current.name" :back="{ name: 'inventory.locations' }">
        <template #back-label>Projects</template>
        <template #badges>
          <GtmPriorityBadge
            v-if="locations.current.gtm_priority"
            :priority="locations.current.gtm_priority"
          />
          <StatusTag :value="locations.current.status" />
        </template>
        <template #subtitle>
          <span class="num">{{ locations.current.code }}</span>
          <template v-if="locations.current.wilaya">
            · {{ locations.current.wilaya.name }}</template
          >
          <template v-if="locations.current.commune">
            ({{ locations.current.commune.name }})</template
          >
        </template>
        <template #actions>
          <Button v-if="canManage" label="Add unit" icon="pi pi-plus" @click="openCreate" />
        </template>
      </PageHeader>

      <!-- Commercial pulse of the project -->
      <div class="mb-5 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <StatCard
          label="Units"
          :value="units.items.length"
          icon="pi pi-th-large"
          :loading="units.loading"
        />
        <StatCard
          label="Available"
          :value="statusCounts.available"
          icon="pi pi-check-circle"
          tone="success"
          :loading="units.loading"
        />
        <StatCard
          label="Reserved"
          :value="statusCounts.reserved"
          icon="pi pi-lock"
          tone="warning"
          :loading="units.loading"
        />
        <StatCard
          label="Sold"
          :value="statusCounts.sold"
          icon="pi pi-flag-fill"
          tone="info"
          :loading="units.loading"
        />
      </div>

      <Tabs value="overview" scrollable>
        <TabList>
          <Tab value="overview"
            ><i class="pi pi-info-circle mr-2" aria-hidden="true" />Overview</Tab
          >
          <Tab value="stacking"><i class="pi pi-table mr-2" aria-hidden="true" />Stacking plan</Tab>
          <Tab value="units"><i class="pi pi-th-large mr-2" aria-hidden="true" />Units</Tab>
          <Tab value="boxes"><i class="pi pi-car mr-2" aria-hidden="true" />Boxes</Tab>
          <Tab value="media"><i class="pi pi-images mr-2" aria-hidden="true" />Media</Tab>
          <Tab value="activity"><i class="pi pi-clock mr-2" aria-hidden="true" />Activity</Tab>
        </TabList>
        <TabPanels class="!px-0 !pt-5">
          <!-- ── Overview ── -->
          <TabPanel value="overview">
            <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
              <SectionCard title="Details" icon="pi pi-info-circle">
                <dl class="space-y-2.5 text-sm">
                  <div class="flex justify-between gap-3">
                    <dt class="text-mute">Address</dt>
                    <dd class="text-right text-ink">
                      {{ locations.current.address || '—' }}
                      <a
                        v-if="mapsUrl"
                        :href="mapsUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="ml-1 text-primary-600 hover:underline dark:text-primary-400"
                      >
                        <i class="pi pi-external-link text-xs" aria-hidden="true" />
                        Maps
                      </a>
                    </dd>
                  </div>
                  <div class="flex justify-between gap-3">
                    <dt class="text-mute">Expected delivery</dt>
                    <dd class="text-ink">
                      {{ formatDate(locations.current.expected_delivery_date) }}
                    </dd>
                  </div>
                  <div class="flex justify-between gap-3">
                    <dt class="text-mute">Contract type</dt>
                    <dd class="text-ink">{{ locations.current.contract_type || '—' }}</dd>
                  </div>
                  <div class="flex justify-between gap-3">
                    <dt class="text-mute">GTM priority</dt>
                    <dd>
                      <GtmPriorityBadge
                        v-if="locations.current.gtm_priority"
                        :priority="locations.current.gtm_priority"
                      />
                      <template v-else>—</template>
                    </dd>
                  </div>
                  <div class="flex justify-between gap-3">
                    <dt class="text-mute">Status</dt>
                    <dd><StatusTag :value="locations.current.status" /></dd>
                  </div>
                </dl>
                <p
                  v-if="locations.current.description"
                  class="mt-3 whitespace-pre-line border-t border-line pt-3 text-sm text-mute"
                >
                  {{ locations.current.description }}
                </p>
              </SectionCard>

              <SectionCard
                v-if="locations.current.latitude != null && locations.current.longitude != null"
                title="Map"
                icon="pi pi-map"
                flush
              >
                <LocationMap
                  :latitude="locations.current.latitude"
                  :longitude="locations.current.longitude"
                />
              </SectionCard>
            </div>
          </TabPanel>

          <!-- ── Stacking plan (colour-coded by sale status) ── -->
          <TabPanel value="stacking">
            <StackingPlan ref="stackingRef" :location-id="props.id">
              <template v-if="canReserve" #actions="{ unit }">
                <Button
                  v-if="unit.sale_status === 'available'"
                  label="Reserve (48h)"
                  icon="pi pi-lock"
                  size="small"
                  :disabled="reserving"
                  @click="reserveUnit(unit)"
                />
                <div
                  v-else-if="unit.sale_status === 'reserved' && unit.reservation_id"
                  class="flex gap-1.5"
                >
                  <Button
                    label="Convert to sale"
                    icon="pi pi-flag"
                    size="small"
                    :disabled="reserving"
                    @click="convertHold(unit)"
                  />
                  <Button
                    label="Release"
                    size="small"
                    severity="secondary"
                    outlined
                    :disabled="reserving"
                    @click="releaseHold(unit)"
                  />
                </div>
              </template>
            </StackingPlan>
          </TabPanel>

          <!-- ── Units ── -->
          <TabPanel value="units">
            <SectionCard flush>
              <DataTable :value="units.items" :loading="units.loading" data-key="id">
                <template #empty>
                  <EmptyState
                    icon="pi pi-th-large"
                    title="No units yet"
                    :body="canManage ? 'Add the first unit to start selling.' : undefined"
                  />
                </template>
                <Column header="Reference">
                  <template #body="{ data }">
                    <RouterLink
                      :to="{ name: 'inventory.unit', params: { id: data.id } }"
                      class="font-medium text-ink hover:underline"
                    >
                      {{ data.reference }}
                    </RouterLink>
                  </template>
                </Column>
                <Column header="Type">
                  <template #body="{ data }">{{ data.type || '—' }}</template>
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
                  <template #body="{ data }"
                    ><SaleStatusBadge :status="data.sale_status"
                  /></template>
                </Column>
                <Column header="Priority">
                  <template #body="{ data }">
                    <GtmPriorityBadge v-if="data.gtm_priority" :priority="data.gtm_priority" />
                    <span v-else class="text-mute">—</span>
                  </template>
                </Column>
                <Column v-if="canManage" header="" class="w-28">
                  <template #body="{ data }">
                    <span class="flex justify-end gap-1">
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
                        @click="remove(data)"
                      />
                    </span>
                  </template>
                </Column>
              </DataTable>
            </SectionCard>
          </TabPanel>

          <!-- ── Boxes (parking / storage) ── -->
          <TabPanel value="boxes">
            <BoxesPanel :location-id="props.id" :units="units.items" :can-manage="canManage" />
          </TabPanel>

          <!-- ── Media (photos / video / PDF / PPTX — all viewed inline) ── -->
          <TabPanel value="media">
            <MediaGallery
              mediable-type="locations"
              :mediable-id="props.id"
              :can-manage="auth.can('media.manage')"
            />
          </TabPanel>

          <!-- ── The record's full audit history ── -->
          <TabPanel value="activity">
            <SectionCard title="Activity log" icon="pi pi-clock">
              <ActivityTimeline :id="Number(props.id)" type="location" />
            </SectionCard>
          </TabPanel>
        </TabPanels>
      </Tabs>
    </template>

    <!-- Create / edit unit specs -->
    <BaseModal
      v-if="(mode === 'create' || mode === 'edit') && canManage"
      :title="mode === 'edit' ? 'Edit unit' : 'New unit'"
      size="max-w-3xl"
      @close="mode = null"
    >
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid gap-3 sm:grid-cols-3">
          <BaseInput v-model="form.reference" label="Reference" />
          <BaseSelect
            v-model="form.type_id"
            label="Type"
            placeholder="— none —"
            :options="unitTypes.map((t) => ({ value: t.id, label: t.label }))"
          />
          <BaseSelect
            v-model="form.floor_id"
            label="Floor"
            placeholder="— none —"
            :options="floors.map((f) => ({ value: f.id, label: f.label }))"
          />
          <BaseInput v-model="form.area_sqm" label="Area (m²)" type="number" />
          <BaseInput v-if="mode === 'create'" v-model="form.price" label="Price" type="number" />
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
        <p v-if="mode === 'edit'" class="text-xs text-mute">
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
          <BaseInput v-model="correction.price" label="Price" type="number" />
          <BaseSelect
            v-model="correction.sale_status"
            label="Sale status"
            :clearable="false"
            :options="[
              { value: 'available', label: 'Available' },
              { value: 'reserved', label: 'Reserved' },
              { value: 'sold', label: 'Sold' },
            ]"
          />
          <BaseInput v-model="correction.reason" label="Reason" />
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
