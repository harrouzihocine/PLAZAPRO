<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
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
  <div class="space-y-4">
    <RouterLink :to="{ name: 'inventory.locations' }" class="text-sm opacity-70 hover:text-primary">
      ← Projects
    </RouterLink>

    <template v-if="locations.current">
      <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h1 class="text-xl font-semibold">{{ locations.current.name }}</h1>
          <p class="opacity-70">
            {{ locations.current.code
            }}<template v-if="locations.current.wilaya"> · {{ locations.current.wilaya.name }}</template
            ><template v-if="locations.current.commune"> ({{ locations.current.commune.name }})</template>
          </p>
        </div>
        <BaseButton v-if="canManage" @click="openCreate">Add unit</BaseButton>
      </div>

      <BaseCard>
        <dl class="grid gap-2 sm:grid-cols-3">
          <div>
            <dt class="text-xs opacity-60">Address</dt>
            <dd>
              {{ locations.current.address || '—' }}
              <a
                v-if="mapsUrl"
                :href="mapsUrl"
                target="_blank"
                rel="noopener noreferrer"
                title="Open in Google Maps"
                aria-label="Open in Google Maps"
                class="ml-1 inline-flex align-middle text-primary hover:opacity-80"
              >
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  width="18"
                  height="18"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  aria-hidden="true"
                >
                  <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z" />
                  <circle cx="12" cy="10" r="3" />
                </svg>
              </a>
            </dd>
          </div>
          <div>
            <dt class="text-xs opacity-60">Expected delivery</dt>
            <dd>{{ locations.current.expected_delivery_date || '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs opacity-60">GTM priority</dt>
            <dd>
              <GtmPriorityBadge
                v-if="locations.current.gtm_priority"
                :priority="locations.current.gtm_priority"
              />
              <template v-else>—</template>
            </dd>
          </div>
          <div>
            <dt class="text-xs opacity-60">Status</dt>
            <dd>{{ locations.current.status }}</dd>
          </div>
          <div>
            <dt class="text-xs opacity-60">Units</dt>
            <dd>{{ units.items.length }}</dd>
          </div>
        </dl>
        <div
          v-if="locations.current.latitude != null && locations.current.longitude != null"
          class="mt-3"
        >
          <LocationMap
            :latitude="locations.current.latitude"
            :longitude="locations.current.longitude"
          />
        </div>
      </BaseCard>

      <!-- Visual stacking plan (colour-coded by sale status) -->
      <StackingPlan ref="stackingRef" :location-id="props.id">
        <template v-if="canReserve" #actions="{ unit }">
          <BaseButton
            v-if="unit.sale_status === 'available'"
            :disabled="reserving"
            @click="reserveUnit(unit)"
          >
            Reserve (48h)
          </BaseButton>
          <div v-else-if="unit.sale_status === 'reserved' && unit.reservation_id" class="flex gap-1">
            <BaseButton :disabled="reserving" @click="convertHold(unit)">Convert to sale</BaseButton>
            <BaseButton variant="ghost" :disabled="reserving" @click="releaseHold(unit)">
              Release
            </BaseButton>
          </div>
        </template>
      </StackingPlan>


      <!-- Create / edit specs form -->
      <BaseCard v-if="(mode === 'create' || mode === 'edit') && canManage">
        <form class="space-y-3" @submit.prevent="submit">
          <h2 class="font-semibold">{{ mode === 'edit' ? 'Edit unit' : 'New unit' }}</h2>
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
          <p v-if="mode === 'edit'" class="text-xs opacity-60">
            To change price or sale status, use “Correct” (keeps the old version).
          </p>
          <div class="flex gap-2">
            <BaseButton type="submit" :disabled="units.saving">Save</BaseButton>
            <BaseButton type="button" variant="ghost" @click="mode = null">Cancel</BaseButton>
          </div>
        </form>
      </BaseCard>

      <!-- Correction (price / sale status) via versioning -->
      <BaseCard v-if="mode === 'correct' && canManage">
        <form class="space-y-3" @submit.prevent="submitCorrection">
          <h2 class="font-semibold">Correct price / status</h2>
          <div class="grid gap-3 sm:grid-cols-3">
            <BaseInput v-model="correction.price" label="Price" type="number" />
            <BaseSelect
              v-model="correction.sale_status"
              label="Sale status"
              :clearable="false"
              :options="[{ value: 'available', label: 'available' }, { value: 'reserved', label: 'reserved' }, { value: 'sold', label: 'sold' }]"
            />
            <BaseInput v-model="correction.reason" label="Reason" />
          </div>
          <p class="text-xs opacity-60">
            This cancels the current row and creates a linked new version — the old value is kept.
          </p>
          <div class="flex gap-2">
            <BaseButton type="submit" :disabled="units.saving">Apply correction</BaseButton>
            <BaseButton type="button" variant="ghost" @click="mode = null">Cancel</BaseButton>
          </div>
        </form>
      </BaseCard>

      <!-- Units table -->
      <BaseCard>
        <p v-if="units.loading" class="py-4 text-center text-sm opacity-60">Loading…</p>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-left opacity-60">
              <tr>
                <th class="py-2 pr-3">Ref</th>
                <th class="py-2 pr-3">Type</th>
                <th class="py-2 pr-3">Floor</th>
                <th class="py-2 pr-3">Price</th>
                <th class="py-2 pr-3">Status</th>
                <th class="py-2 pr-3">Priority</th>
                <th v-if="canManage" class="py-2"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="u in units.items" :key="u.id" class="border-t border-border">
                <td class="py-2 pr-3 font-medium">
                  <RouterLink :to="{ name: 'inventory.unit', params: { id: u.id } }" class="hover:text-primary">
                    {{ u.reference }}
                  </RouterLink>
                </td>
                <td class="py-2 pr-3">{{ u.type || '—' }}</td>
                <td class="py-2 pr-3">{{ u.floor || '—' }}</td>
                <td class="py-2 pr-3">{{ u.price }}</td>
                <td class="py-2 pr-3"><SaleStatusBadge :status="u.sale_status" /></td>
                <td class="py-2 pr-3">
                  <GtmPriorityBadge v-if="u.gtm_priority" :priority="u.gtm_priority" />
                  <span v-else>—</span>
                </td>
                <td v-if="canManage" class="py-2">
                  <div class="flex justify-end gap-1">
                    <BaseButton variant="ghost" @click="openEdit(u)">Edit</BaseButton>
                    <BaseButton variant="ghost" @click="openCorrect(u)">Correct</BaseButton>
                    <BaseButton variant="ghost" @click="remove(u)">Remove</BaseButton>
                  </div>
                </td>
              </tr>
              <tr v-if="!units.items.length">
                <td colspan="7" class="py-4 text-center text-sm opacity-60">No units yet.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </BaseCard>

      <!-- Boxes (parking / storage) for this project -->
      <BoxesPanel :location-id="props.id" :units="units.items" :can-manage="canManage" />

      <!-- Media gallery (photos / video / PDF / PPTX — all viewed inline) -->
      <MediaGallery
        mediable-type="locations"
        :mediable-id="props.id"
        :can-manage="auth.can('media.manage')"
      />
    </template>

    <p v-else class="py-4 text-center text-sm opacity-60">Loading…</p>
  </div>
</template>
