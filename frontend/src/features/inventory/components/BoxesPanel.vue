<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useDynamicList } from '@/composables/useDynamicList'
import SaleStatusBadge from '@/features/inventory/components/SaleStatusBadge.vue'
import { useBoxesStore } from '@/features/inventory/boxesStore'
import { confirmAction } from '@/composables/useConfirm'
import { formatMoney } from '@/features/payments/money'

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

async function remove(b) {
  if (
    await confirmAction({
      title: `Cancel box "${b.reference}"?`,
      confirmText: 'Cancel box',
      danger: true,
    })
  ) {
    boxes.cancel(b.id)
  }
}
</script>

<template>
  <SectionCard title="Boxes (parking / storage)" icon="pi pi-car" flush>
    <template #actions>
      <Button v-if="canManage" label="Add box" icon="pi pi-plus" size="small" @click="openCreate" />
    </template>

    <form
      v-if="mode && canManage"
      class="grid gap-3 border-b border-line px-4 py-4 sm:grid-cols-3 sm:px-5"
      @submit.prevent="submit"
    >
      <BaseInput v-model="form.reference" label="Reference" />
      <BaseSelect
        v-model="form.type_id"
        label="Type"
        placeholder="— none —"
        :options="boxTypes.map((t) => ({ value: t.id, label: t.label }))"
      />
      <BaseInput v-model="form.price" label="Price" type="number" />
      <BaseSelect
        v-model="form.unit_id"
        label="Linked unit"
        placeholder="— none —"
        :options="units.map((u) => ({ value: u.id, label: u.reference }))"
      />
      <BaseSelect
        v-model="form.sale_status"
        label="Sale status"
        :clearable="false"
        :options="[
          { value: 'available', label: 'Available' },
          { value: 'reserved', label: 'Reserved' },
          { value: 'sold', label: 'Sold' },
        ]"
      />
      <div class="flex items-end gap-2">
        <Button type="submit" label="Save" icon="pi pi-check" :loading="boxes.saving" />
        <Button type="button" label="Cancel" severity="secondary" outlined @click="mode = null" />
      </div>
    </form>

    <DataTable :value="boxes.items" data-key="id">
      <template #empty>
        <EmptyState
          icon="pi pi-car"
          title="No boxes yet"
          :body="canManage ? 'Add parking or storage boxes for this project.' : undefined"
        />
      </template>
      <Column header="Reference">
        <template #body="{ data }">
          <span class="font-medium text-ink">{{ data.reference }}</span>
        </template>
      </Column>
      <Column header="Type">
        <template #body="{ data }">{{ data.type || '—' }}</template>
      </Column>
      <Column header="Price">
        <template #body="{ data }">
          <span class="num">{{ formatMoney(data.price) }}</span>
        </template>
      </Column>
      <Column header="Linked unit">
        <template #body="{ data }">
          {{ data.unit_id ? (unitRef[data.unit_id] ?? data.unit_id) : '—' }}
        </template>
      </Column>
      <Column header="Status">
        <template #body="{ data }"><SaleStatusBadge :status="data.sale_status" /></template>
      </Column>
      <Column v-if="canManage" header="" class="w-24">
        <template #body="{ data }">
          <span class="flex justify-end gap-1">
            <Button
              icon="pi pi-pencil"
              text
              rounded
              size="small"
              severity="secondary"
              aria-label="Edit box"
              @click="openEdit(data)"
            />
            <Button
              icon="pi pi-ban"
              text
              rounded
              size="small"
              severity="danger"
              aria-label="Cancel box"
              @click="remove(data)"
            />
          </span>
        </template>
      </Column>
    </DataTable>
  </SectionCard>
</template>
