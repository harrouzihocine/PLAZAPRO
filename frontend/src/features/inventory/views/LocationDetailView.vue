<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRefreshable } from '@/composables/useRefreshRegistry'
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
import MoneyInput from '@/components/base/MoneyInput.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import StatCard from '@/components/ui/StatCard.vue'
import StatusTag from '@/components/ui/StatusTag.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ActivityTimeline from '@/components/ui/ActivityTimeline.vue'
import { useDynamicList, itemLabel } from '@/composables/useDynamicList'
import { gtmPriorityOptions, locationsApi, mediaFileUrl, reservationsApi } from '@/features/inventory/api'
import BoxesPanel from '@/features/inventory/components/BoxesPanel.vue'
import GtmPriorityBadge from '@/features/inventory/components/GtmPriorityBadge.vue'
import LocationMap from '@/features/inventory/components/LocationMap.vue'
import MediaGallery from '@/features/inventory/components/MediaGallery.vue'
import SaleStatusBadge from '@/features/inventory/components/SaleStatusBadge.vue'
import StackingPlan from '@/features/inventory/components/StackingPlan.vue'
import { googleMapsUrl } from '@/features/inventory/googleMaps'
import { copyToClipboard } from '@/composables/useClipboard'
import { useLocationsStore } from '@/features/inventory/locationsStore'
import { useUnitsStore } from '@/features/inventory/unitsStore'
import { useAuthStore } from '@/features/settings/store'
import { confirmAction } from '@/composables/useConfirm'
import { formatDate } from '@/utils/format'
import { isNativeApp } from '@/utils/nativeApp'
import { formatMoney } from '@/features/payments/money'
import { t } from '@/i18n'
import FeedbackPanel from '@/features/analytics/components/FeedbackPanel.vue'

// One project's workspace, organised in tabs so nothing drowns: Overview
// (facts + map), Stacking plan, Units, Boxes, Media, and the full Activity
// (audit) history the record accumulated.
const props = defineProps({ id: { type: [String, Number], required: true } })
const locations = useLocationsStore()
const units = useUnitsStore()
const auth = useAuthStore()
const { items: roomNumbers } = useDynamicList('room_numbers')
const { items: floors } = useDynamicList('floors')

const canManage = auth.can('units.manage')
const canMarkInterest = auth.can('units.interest')
// Voice-of-Client analytics is manager-level commercial intelligence.
const canSeeFeedback = auth.can('reports.view')

const mapsUrl = computed(() => googleMapsUrl(locations.current ?? {}))

// Android shell: fire the address + Maps link straight into WhatsApp (its
// contact picker opens). wa.me without a number = "share to anyone".
const isNative = isNativeApp()
const whatsappMapsUrl = computed(() => {
  const place = [locations.current?.name, locations.current?.address].filter(Boolean).join(' — ')
  return `https://wa.me/?text=${encodeURIComponent(`${place}\n${mapsUrl.value}`)}`
})

const stackingRef = ref(null)
const holdBusy = ref(false)
const insights = ref(null)

// Sale-status mix across the project's units — the at-a-glance commercial state.
const statusCounts = computed(() => {
  const counts = { available: 0, interested: 0, reserved: 0, sold: 0 }
  for (const u of units.items) {
    if (u.sale_status in counts) counts[u.sale_status] += 1
  }
  return counts
})

// Interest-hold lifecycle from the stacking-plan cell. Refresh both the plan
// (for the colour + countdown) and the units table (for the sale_status)
// afterwards.
async function afterHold(fn) {
  holdBusy.value = true
  try {
    await fn()
    await Promise.all([stackingRef.value?.reload(), units.fetchForLocation(props.id)])
  } catch (e) {
    units.error = e.response?.data?.message ?? t('common.actionFailed')
  } finally {
    holdBusy.value = false
  }
}

const markInterested = (unit) => afterHold(() => reservationsApi.markInterest(unit.id))
const releaseHold = (unit) => afterHold(() => reservationsApi.release(unit.reservation_id))
const convertHold = (unit) => afterHold(() => reservationsApi.convert(unit.reservation_id))

const blank = {
  reference: '',
  room_number_id: '',
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

function load() {
  // The three reads are independent — fire them together instead of chaining
  // so the page paints as soon as the slowest one returns, not their sum.
  locations.fetchOne(props.id)
  units.fetchForLocation(props.id)
  return locationsApi
    .insights(props.id)
    .then((data) => (insights.value = data))
    .catch(() => (insights.value = null))
}
onMounted(load)
useRefreshable(load) // pull-to-refresh (APK)

function openCreate() {
  Object.assign(form, blank)
  editingId.value = null
  mode.value = 'create'
}

function openEdit(u) {
  Object.assign(form, {
    reference: u.reference,
    room_number_id: u.room_number_id ?? '',
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
    room_number_id: form.room_number_id || null,
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
      title: t('inventory.cancelUnitTitle', { ref: u.reference }),
      text: t('project.removeText'),
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
    <div v-if="!locations.current" class="space-y-4">
      <Skeleton width="18rem" height="2rem" />
      <Skeleton height="12rem" />
    </div>

    <template v-else>
      <!-- Cover hero (only when a cover picture is set) -->
      <div
        v-if="locations.current.cover_media_id"
        class="relative mb-5 h-44 overflow-hidden rounded-2xl border border-line sm:h-56"
      >
        <img
          :src="mediaFileUrl(locations.current.cover_media_id)"
          :alt="locations.current.name"
          class="h-full w-full object-cover"
          :style="{
            objectPosition: `${locations.current.cover_focus_x ?? 50}% ${locations.current.cover_focus_y ?? 50}%`,
          }"
        />
        <div class="absolute inset-0 bg-gradient-to-t from-black/55 via-black/10 to-transparent" />
        <div class="absolute bottom-0 start-0 p-5">
          <h1 class="text-2xl font-bold text-white drop-shadow-sm">{{ locations.current.name }}</h1>
          <p class="num mt-0.5 text-sm text-white/80">
            {{ locations.current.code }}
            <template v-if="locations.current.wilaya"> · {{ locations.current.wilaya.name }}</template>
          </p>
        </div>
      </div>

      <PageHeader :title="locations.current.name" :back="{ name: 'inventory.locations' }">
        <template #back-label>{{ $t('inventory.projects') }}</template>
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
          <Button
            v-if="canManage"
:label="$t('inventory.addUnit')"
            icon="pi pi-plus"
            class="native-fab"
            @click="openCreate"
          />
        </template>
      </PageHeader>

      <!-- Commercial pulse of the project -->
      <div class="mb-5 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <StatCard
:label="$t('nav.units')"
          :value="units.items.length"
          icon="pi pi-th-large"
          :loading="units.loading"
        />
        <StatCard
:label="$t('status.available')"
          :value="statusCounts.available"
          icon="pi pi-check-circle"
          tone="success"
          :loading="units.loading"
        />
        <StatCard
:label="$t('status.interested')"
          :value="statusCounts.interested"
          icon="pi pi-thumbs-up"
          tone="warning"
          :hint="statusCounts.reserved ? $t('inventory.nReserved', { n: statusCounts.reserved }) : ''"
          :loading="units.loading"
        />
        <StatCard
:label="$t('status.sold')"
          :value="statusCounts.sold"
          icon="pi pi-flag-fill"
          tone="info"
          :loading="units.loading"
        />
      </div>

      <!-- lazy: only the active tab's panel is mounted, so opening the page
           doesn't eagerly boot the stacking grid, both DataTables, the media
           gallery and the activity feed (each of which fetches on mount). -->
      <Tabs value="overview" scrollable lazy>
        <TabList>
          <Tab value="overview"
            ><i class="pi pi-info-circle me-2" aria-hidden="true" />{{ $t('inventory.tabOverview') }}</Tab
          >
          <Tab value="performance"
            ><i class="pi pi-chart-line me-2" aria-hidden="true" />{{ $t('inventory.tabPerformance') }}</Tab
          >
          <Tab v-if="canSeeFeedback" value="feedback"
            ><i class="pi pi-comments me-2" aria-hidden="true" />{{ $t('inventory.tabVoiceOfClient') }}</Tab
          >
          <Tab value="stacking"><i class="pi pi-table me-2" aria-hidden="true" />{{ $t('inventory.tabStacking') }}</Tab>
          <Tab value="units"><i class="pi pi-th-large me-2" aria-hidden="true" />{{ $t('nav.units') }}</Tab>
          <Tab value="boxes"><i class="pi pi-car me-2" aria-hidden="true" />{{ $t('inventory.tabBoxes') }}</Tab>
          <Tab value="media"><i class="pi pi-images me-2" aria-hidden="true" />{{ $t('inventory.tabMedia') }}</Tab>
          <Tab value="activity"><i class="pi pi-clock me-2" aria-hidden="true" />{{ $t('inventory.tabActivity') }}</Tab>
        </TabList>
        <TabPanels class="!px-0 !pt-5">
          <!-- ── Overview ── -->
          <TabPanel value="overview">
            <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
              <SectionCard :title="$t('project.details')" icon="pi pi-info-circle">
                <dl class="space-y-2.5 text-sm">
                  <div class="flex justify-between gap-3">
                    <dt class="text-mute">{{ $t('common.address') }}</dt>
                    <dd class="text-end text-ink">
                      {{ locations.current.address || '—' }}
                      <template v-if="mapsUrl">
                        <a
                          :href="mapsUrl"
                          target="_blank"
                          rel="noopener noreferrer"
                          class="ms-1 text-primary-600 hover:underline dark:text-primary-400"
                        >
                          <i class="pi pi-external-link text-xs" aria-hidden="true" />
                          {{ $t('inventory.maps') }}
                        </a>
                        <button
                          type="button"
:title="$t('pipeline.copyMapsLink')"
                          :aria-label="$t('pipeline.copyMapsLink')"
                          class="ms-1 text-primary-600 hover:underline dark:text-primary-400"
                          @click="copyToClipboard(mapsUrl, $t('pipeline.mapsLinkCopied'))"
                        >
                          <i class="pi pi-copy text-xs" aria-hidden="true" />
                        </button>
                        <a
                          v-if="isNative"
                          :href="whatsappMapsUrl"
                          target="_blank"
                          rel="noopener"
:title="$t('inventory.sendWhatsApp')"
                          :aria-label="$t('inventory.sendWhatsAppAria')"
                          class="ms-1 text-emerald-600 dark:text-emerald-400"
                        >
                          <i class="pi pi-whatsapp text-xs" aria-hidden="true" />
                        </a>
                      </template>
                    </dd>
                  </div>
                  <div class="flex justify-between gap-3">
                    <dt class="text-mute">{{ $t('inventory.expectedDelivery') }}</dt>
                    <dd class="text-ink">
                      {{ formatDate(locations.current.expected_delivery_date) }}
                    </dd>
                  </div>
                  <div class="flex justify-between gap-3">
                    <dt class="text-mute">{{ $t('inventory.contractType') }}</dt>
                    <dd class="text-ink">{{ locations.current.contract_type || '—' }}</dd>
                  </div>
                  <div class="flex justify-between gap-3">
                    <dt class="shrink-0 text-mute">{{ $t('inventory.paymentMethods') }}</dt>
                    <dd class="text-end text-ink">
                      <span
                        v-if="locations.current.payment_methods?.length"
                        class="flex flex-wrap justify-end gap-1"
                      >
                        <span
                          v-for="m in locations.current.payment_methods"
                          :key="m.id"
                          class="inline-flex items-center rounded-full bg-highlight px-2 py-0.5 text-xs text-ink"
                        >
                          {{ m.label }}
                        </span>
                      </span>
                      <template v-else>—</template>
                    </dd>
                  </div>
                  <div class="flex justify-between gap-3">
                    <dt class="text-mute">{{ $t('inventory.gtmPriority') }}</dt>
                    <dd>
                      <GtmPriorityBadge
                        v-if="locations.current.gtm_priority"
                        :priority="locations.current.gtm_priority"
                      />
                      <template v-else>—</template>
                    </dd>
                  </div>
                  <div class="flex justify-between gap-3">
                    <dt class="text-mute">{{ $t('common.status') }}</dt>
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
:title="$t('inventory.map')"
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

          <!-- ── Performance (funnel, pipeline, revenue) ── -->
          <TabPanel value="performance">
            <div v-if="insights" class="space-y-5">
              <SectionCard :title="$t('inventory.funnel')" icon="pi pi-filter">
                <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
                  <StatCard :label="$t('nav.units')" :value="insights.units.total" icon="pi pi-th-large" />
                  <StatCard :label="$t('status.available')" :value="insights.units.available" icon="pi pi-check-circle" tone="success" />
                  <StatCard :label="$t('status.interested')" :value="insights.units.interested" icon="pi pi-thumbs-up" tone="warning" />
                  <StatCard :label="$t('status.sold')" :value="insights.units.sold" icon="pi pi-flag-fill" tone="info" />
                </div>
                <div v-if="insights.boxes.total" class="mt-3 grid grid-cols-3 gap-3 sm:gap-4">
                  <StatCard :label="$t('inventory.tabBoxes')" :value="insights.boxes.total" icon="pi pi-car" />
                  <StatCard :label="$t('inventory.boxesAvailable')" :value="insights.boxes.available" icon="pi pi-check-circle" tone="success" />
                  <StatCard :label="$t('inventory.boxesSold')" :value="insights.boxes.sold" icon="pi pi-flag-fill" tone="info" />
                </div>
              </SectionCard>

              <SectionCard :title="$t('nav.pipeline')" icon="pi pi-briefcase">
                <div class="grid grid-cols-3 gap-3 sm:gap-4">
                  <StatCard :label="$t('inventory.activeProjects')" :value="insights.pipeline.active_projects" icon="pi pi-users" />
                  <StatCard :label="$t('status.won')" :value="insights.pipeline.won" icon="pi pi-trophy" tone="success" />
                  <StatCard :label="$t('status.lost')" :value="insights.pipeline.lost" icon="pi pi-times-circle" tone="danger" />
                </div>
              </SectionCard>

              <SectionCard v-if="insights.revenue" :title="$t('inventory.revenue')" icon="pi pi-money-bill">
                <div class="grid grid-cols-2 gap-3 sm:gap-4">
                  <StatCard :label="$t('inventory.collected')" :value="formatMoney(insights.revenue.collected)" icon="pi pi-wallet" tone="success" />
                  <StatCard :label="$t('inventory.soldValue')" :value="formatMoney(insights.revenue.sold_value)" icon="pi pi-chart-line" tone="info" />
                </div>
              </SectionCard>
            </div>
            <EmptyState
              v-else
              icon="pi pi-chart-line"
:title="$t('inventory.noPerformanceTitle')"
              :body="$t('inventory.noPerformanceBody')"
            />
          </TabPanel>

          <!-- ── Voice of Client (log-mined feedback analytics) ── -->
          <TabPanel v-if="canSeeFeedback" value="feedback">
            <FeedbackPanel :id="props.id" scope="location" />
          </TabPanel>

          <!-- ── Stacking plan (colour-coded by sale status) ── -->
          <TabPanel value="stacking">
            <StackingPlan ref="stackingRef" :location-id="props.id">
              <template v-if="canMarkInterest" #actions="{ unit }">
                <Button
                  v-if="unit.sale_status === 'available'"
:label="$t('inventory.markInterested')"
                  icon="pi pi-thumbs-up"
                  size="small"
                  :disabled="holdBusy"
                  @click="markInterested(unit)"
                />
                <div
                  v-else-if="unit.sale_status === 'interested' && unit.reservation_id"
                  class="flex gap-1.5"
                >
                  <Button
:label="$t('inventory.convertToSale')"
                    icon="pi pi-flag"
                    size="small"
                    :disabled="holdBusy"
                    @click="convertHold(unit)"
                  />
                  <Button
:label="$t('deal.release')"
                    size="small"
                    severity="secondary"
                    outlined
                    :disabled="holdBusy"
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
:title="$t('inventory.noUnitsTitle')"
                    :body="canManage ? $t('inventory.noUnitsBody') : undefined"
                  />
                </template>
                <Column :header="$t('inventory.reference')">
                  <template #body="{ data }">
                    <RouterLink
                      :to="{ name: 'inventory.unit', params: { id: data.id } }"
                      class="font-medium text-ink hover:underline"
                    >
                      {{ data.reference }}
                    </RouterLink>
                  </template>
                </Column>
                <Column :header="$t('inventory.type')">
                  <template #body="{ data }">{{ data.type || '—' }}</template>
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
                    <span class="num">{{ formatMoney(data.price) }}</span>
                  </template>
                </Column>
                <Column :header="$t('common.status')">
                  <template #body="{ data }"
                    ><SaleStatusBadge :status="data.sale_status"
                  /></template>
                </Column>
                <Column :header="$t('tasks.priority')">
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
                        icon="pi pi-ban"
                        text
                        rounded
                        size="small"
                        severity="danger"
:aria-label="$t('inventory.cancelUnit')"
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
            <SectionCard :title="$t('inventory.activityLog')" icon="pi pi-clock">
              <ActivityTimeline :id="Number(props.id)" type="location" />
            </SectionCard>
          </TabPanel>
        </TabPanels>
      </Tabs>
    </template>

    <!-- Create / edit unit specs -->
    <BaseModal
      v-if="(mode === 'create' || mode === 'edit') && canManage"
      :title="mode === 'edit' ? $t('inventory.editUnit') : $t('inventory.newUnit')"
      size="max-w-3xl"
      @close="mode = null"
    >
      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid gap-3 sm:grid-cols-3">
          <BaseInput v-model="form.reference" :label="$t('inventory.reference')" required />
          <!-- Project type is a project attribute the unit inherits — shown
               read-only for context (edit it on the project). -->
          <div>
            <label class="mb-1 block text-sm font-medium text-mute">{{ $t('inventory.projectType') }}</label>
            <p class="rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink">
              {{ locations.current?.type || '—' }}
            </p>
          </div>
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
          <!-- Contract type is a project attribute the unit inherits — shown
               read-only for context (edit it on the project). -->
          <div>
            <label class="mb-1 block text-sm font-medium text-mute">{{ $t('inventory.contractType') }}</label>
            <p class="rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink">
              {{ locations.current?.contract_type || '—' }}
            </p>
          </div>
          <MoneyInput v-if="mode === 'create'" v-model="form.price" :label="$t('inventory.price')" required />
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
        <p v-if="mode === 'edit'" class="text-xs text-mute">
          {{ $t('inventory.useCorrectHint') }}
        </p>
        <div class="flex gap-2">
          <Button type="submit" :label="$t('common.save')" icon="pi pi-check" :loading="units.saving" />
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
          <MoneyInput v-model="correction.price" :label="$t('inventory.price')" />
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
