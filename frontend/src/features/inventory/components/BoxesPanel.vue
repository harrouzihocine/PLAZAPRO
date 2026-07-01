<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import { useDynamicList } from '@/composables/useDynamicList'
import SaleStatusBadge from '@/features/inventory/components/SaleStatusBadge.vue'
import { useBoxesStore } from '@/features/inventory/boxesStore'

const props = defineProps({
  locationId: { type: [String, Number], required: true },
  units: { type: Array, default: () => [] },
  canManage: { type: Boolean, default: false },
})

const boxes = useBoxesStore()
const { items: boxTypes } = useDynamicList('box_types')

// Map unit id -> reference so the table shows the linked unit, not a raw FK.
const unitRef = computed(() => Object.fromEntries(props.units.map((u) => [u.id, u.reference])))

const blank = { reference: '', type_id: '', price: '', sale_status: 'available', unit_id: '' }
const form = reactive({ ...blank })
const mode = ref(null) // 'create' | 'edit' | null
const editingId = ref(null)

onMounted(() => boxes.fetchForLocation(props.locationId))

function openCreate() {
  Object.assign(form, blank)
  editingId.value = null
  mode.value = 'create'
}

function openEdit(b) {
  Object.assign(form, {
    reference: b.reference,
    type_id: b.type_id ?? '',
    price: b.price ?? '',
    sale_status: b.sale_status,
    unit_id: b.unit_id ?? '',
  })
  editingId.value = b.id
  mode.value = 'edit'
}

async function submit() {
  const payload = {
    reference: form.reference.trim(),
    type_id: form.type_id || null,
    price: form.price === '' ? null : Number(form.price),
    sale_status: form.sale_status,
    unit_id: form.unit_id || null,
  }
  try {
    if (mode.value === 'edit') {
      await boxes.update(editingId.value, payload)
    } else {
      await boxes.create(props.locationId, payload)
    }
    mode.value = null
  } catch {
    /* surfaced via boxes.error */
  }
}

function remove(b) {
  if (window.confirm(`Cancel box "${b.reference}"?`)) boxes.cancel(b.id)
}
</script>

<template>
  <BaseCard>
    <div class="flex items-center justify-between">
      <h2 class="font-semibold">Boxes (parking / storage)</h2>
      <BaseButton v-if="canManage" variant="ghost" @click="openCreate">Add box</BaseButton>
    </div>

    <p v-if="boxes.error" class="mt-2 text-sm text-danger">{{ boxes.error }}</p>

    <form
      v-if="mode && canManage"
      class="mt-3 grid gap-3 border-t border-border pt-3 sm:grid-cols-3"
      @submit.prevent="submit"
    >
      <BaseInput v-model="form.reference" label="Reference" />
      <label class="block">
        <span class="mb-1 block text-sm">Type</span>
        <select v-model="form.type_id" class="w-full rounded-token border border-border bg-bg px-3 py-2 min-h-[44px] text-ink">
          <option value="">— none —</option>
          <option v-for="t in boxTypes" :key="t.id" :value="t.id">{{ t.label }}</option>
        </select>
      </label>
      <BaseInput v-model="form.price" label="Price" type="number" />
      <label class="block">
        <span class="mb-1 block text-sm">Linked unit</span>
        <select v-model="form.unit_id" class="w-full rounded-token border border-border bg-bg px-3 py-2 min-h-[44px] text-ink">
          <option value="">— none —</option>
          <option v-for="u in units" :key="u.id" :value="u.id">{{ u.reference }}</option>
        </select>
      </label>
      <label class="block">
        <span class="mb-1 block text-sm">Sale status</span>
        <select v-model="form.sale_status" class="w-full rounded-token border border-border bg-bg px-3 py-2 min-h-[44px] text-ink">
          <option value="available">available</option>
          <option value="reserved">reserved</option>
          <option value="sold">sold</option>
        </select>
      </label>
      <div class="flex items-end gap-2">
        <BaseButton type="submit" :disabled="boxes.saving">Save</BaseButton>
        <BaseButton type="button" variant="ghost" @click="mode = null">Cancel</BaseButton>
      </div>
    </form>

    <div class="mt-3 overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="text-left opacity-60">
          <tr>
            <th class="py-2 pr-3">Ref</th>
            <th class="py-2 pr-3">Type</th>
            <th class="py-2 pr-3">Price</th>
            <th class="py-2 pr-3">Unit</th>
            <th class="py-2 pr-3">Status</th>
            <th v-if="canManage" class="py-2"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="b in boxes.items" :key="b.id" class="border-t border-border">
            <td class="py-2 pr-3 font-medium">{{ b.reference }}</td>
            <td class="py-2 pr-3">{{ b.type || '—' }}</td>
            <td class="py-2 pr-3">{{ b.price }}</td>
            <td class="py-2 pr-3">{{ b.unit_id ? (unitRef[b.unit_id] ?? b.unit_id) : '—' }}</td>
            <td class="py-2 pr-3"><SaleStatusBadge :status="b.sale_status" /></td>
            <td v-if="canManage" class="py-2">
              <div class="flex justify-end gap-1">
                <BaseButton variant="ghost" @click="openEdit(b)">Edit</BaseButton>
                <BaseButton variant="ghost" @click="remove(b)">Remove</BaseButton>
              </div>
            </td>
          </tr>
          <tr v-if="!boxes.items.length">
            <td colspan="6" class="py-4 text-center text-sm opacity-60">No boxes yet.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </BaseCard>
</template>
