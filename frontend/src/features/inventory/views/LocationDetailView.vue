<script setup>
import { onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import { useDynamicList } from '@/composables/useDynamicList'
import { reservationsApi } from '@/features/inventory/api'
import BoxesPanel from '@/features/inventory/components/BoxesPanel.vue'
import MediaGallery from '@/features/inventory/components/MediaGallery.vue'
import SaleStatusBadge from '@/features/inventory/components/SaleStatusBadge.vue'
import StackingPlan from '@/features/inventory/components/StackingPlan.vue'
import { useLocationsStore } from '@/features/inventory/locationsStore'
import { useUnitsStore } from '@/features/inventory/unitsStore'
import { useAuthStore } from '@/features/settings/store'

const props = defineProps({ id: { type: [String, Number], required: true } })
const locations = useLocationsStore()
const units = useUnitsStore()
const auth = useAuthStore()
const { items: unitTypes } = useDynamicList('unit_types')
const { items: floors } = useDynamicList('floors')

const canManage = auth.can('units.manage')
const canReserve = auth.can('units.reserve')

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
  rooms: '',
  price: '',
  block: '',
  stack_floor: '',
  position: '',
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
    rooms: u.rooms ?? '',
    price: u.price ?? '',
    block: u.block ?? '',
    stack_floor: u.stack_floor ?? '',
    position: u.position ?? '',
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
    rooms: num(form.rooms),
    block: form.block.trim() || null,
    stack_floor: num(form.stack_floor),
    position: num(form.position),
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

function remove(u) {
  if (window.confirm(`Cancel unit "${u.reference}"? The record is kept but marked cancelled.`)) {
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
            }}<template v-if="locations.current.area"> · {{ locations.current.area.label }}</template>
          </p>
        </div>
        <BaseButton v-if="canManage" @click="openCreate">Add unit</BaseButton>
      </div>

      <BaseCard>
        <dl class="grid gap-2 sm:grid-cols-3">
          <div>
            <dt class="text-xs opacity-60">Address</dt>
            <dd>{{ locations.current.address || '—' }}</dd>
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

      <p v-if="units.error" class="text-sm text-danger">{{ units.error }}</p>

      <!-- Create / edit specs form -->
      <BaseCard v-if="(mode === 'create' || mode === 'edit') && canManage">
        <form class="space-y-3" @submit.prevent="submit">
          <h2 class="font-semibold">{{ mode === 'edit' ? 'Edit unit' : 'New unit' }}</h2>
          <div class="grid gap-3 sm:grid-cols-3">
            <BaseInput v-model="form.reference" label="Reference" />
            <label class="block">
              <span class="mb-1 block text-sm">Type</span>
              <select v-model="form.type_id" class="w-full rounded-token border border-border bg-bg px-3 py-2 min-h-[44px] text-ink">
                <option value="">— none —</option>
                <option v-for="t in unitTypes" :key="t.id" :value="t.id">{{ t.label }}</option>
              </select>
            </label>
            <label class="block">
              <span class="mb-1 block text-sm">Floor</span>
              <select v-model="form.floor_id" class="w-full rounded-token border border-border bg-bg px-3 py-2 min-h-[44px] text-ink">
                <option value="">— none —</option>
                <option v-for="f in floors" :key="f.id" :value="f.id">{{ f.label }}</option>
              </select>
            </label>
            <BaseInput v-model="form.area_sqm" label="Area (m²)" type="number" />
            <BaseInput v-model="form.rooms" label="Rooms" type="number" />
            <BaseInput v-if="mode === 'create'" v-model="form.price" label="Price" type="number" />
            <BaseInput v-model="form.block" label="Block" />
            <BaseInput v-model="form.stack_floor" label="Stack floor" type="number" />
            <BaseInput v-model="form.position" label="Position" type="number" />
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
            <label class="block">
              <span class="mb-1 block text-sm">Sale status</span>
              <select v-model="correction.sale_status" class="w-full rounded-token border border-border bg-bg px-3 py-2 min-h-[44px] text-ink">
                <option value="available">available</option>
                <option value="reserved">reserved</option>
                <option value="sold">sold</option>
              </select>
            </label>
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
                <th v-if="canManage" class="py-2"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="u in units.items" :key="u.id" class="border-t border-border">
                <td class="py-2 pr-3 font-medium">{{ u.reference }}</td>
                <td class="py-2 pr-3">{{ u.type || '—' }}</td>
                <td class="py-2 pr-3">{{ u.floor || '—' }}</td>
                <td class="py-2 pr-3">{{ u.price }}</td>
                <td class="py-2 pr-3"><SaleStatusBadge :status="u.sale_status" /></td>
                <td v-if="canManage" class="py-2">
                  <div class="flex justify-end gap-1">
                    <BaseButton variant="ghost" @click="openEdit(u)">Edit</BaseButton>
                    <BaseButton variant="ghost" @click="openCorrect(u)">Correct</BaseButton>
                    <BaseButton variant="ghost" @click="remove(u)">Remove</BaseButton>
                  </div>
                </td>
              </tr>
              <tr v-if="!units.items.length">
                <td colspan="6" class="py-4 text-center text-sm opacity-60">No units yet.</td>
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
