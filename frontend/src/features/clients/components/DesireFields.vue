<script setup>
import { onMounted, watch } from 'vue'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseMultiSelect from '@/components/base/BaseMultiSelect.vue'
import MoneyInput from '@/components/base/MoneyInput.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'
import { useDynamicList, itemLabel } from '@/composables/useDynamicList'
import { useWilayas, useCommunes } from '@/composables/useGeography'
import { useLocationsStore } from '@/features/inventory/locationsStore'

// The desire-profile fieldset (Branch A — no matching inventory), shared by the
// call-log form and the shift-to-desire modal. Max detail, mirroring the unit
// form: type + floor selectors, area and budget ranges, preferred sites.
// Notes are REQUIRED — the story behind the numbers. Emits a merged object so
// the parent owns the value: { wilaya_id, commune_id, type_id, room_number_id,
// contract_type_id, floor_id, area_min, area_max, rooms_min, budget_min,
// budget_max, location_ids, notes }.
const props = defineProps({ modelValue: { type: Object, required: true } })
const emit = defineEmits(['update:modelValue'])

const { wilayas } = useWilayas()
const { communes, load: loadCommunes } = useCommunes()
const { items: projectTypes } = useDynamicList('project_types')
const { items: roomNumbers } = useDynamicList('room_numbers')
const { items: contractTypes } = useDynamicList('contract_types')
const { items: floors } = useDynamicList('floors')
const locations = useLocationsStore()

onMounted(async () => {
  if (!locations.items.length) await locations.fetch()
})

function update(field, value) {
  emit('update:modelValue', { ...props.modelValue, [field]: value })
}

// A USER wilaya change clears the picked commune (the list is about to change);
// programmatic fills (loading a saved desire) go through the watcher below, which
// only loads the commune list and keeps the saved commune selected.
function updateWilaya(value) {
  emit('update:modelValue', { ...props.modelValue, wilaya_id: value, commune_id: '' })
}

watch(
  () => props.modelValue.wilaya_id,
  (id) => loadCommunes(id),
  { immediate: true },
)
</script>

<template>
  <div class="grid gap-3 sm:grid-cols-2">
    <BaseSelect
      :label="$t('geo.wilaya')"
      :placeholder="$t('common.any')"
      :model-value="modelValue.wilaya_id"
      :options="wilayas.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))"
      @change="updateWilaya"
    />
    <BaseSelect
      :label="$t('geo.commune')"
      :placeholder="$t('common.any')"
      :disabled="!modelValue.wilaya_id"
      :model-value="modelValue.commune_id"
      :options="communes.map((c) => ({ value: c.id, label: c.name }))"
      @change="(v) => update('commune_id', v)"
    />
    <BaseMultiSelect
      class="sm:col-span-2"
      :label="$t('desire.preferredSites')"
      :placeholder="$t('desire.anySite')"
      :model-value="modelValue.location_ids ?? []"
      :options="locations.items.map((l) => ({ value: l.id, label: l.name }))"
      @update:model-value="(v) => update('location_ids', v)"
    />
    <BaseSelect
      :label="$t('inventory.projectType')"
      :placeholder="$t('common.any')"
      :model-value="modelValue.type_id"
      :options="projectTypes.map((t) => ({ value: t.id, label: itemLabel(t) }))"
      @change="(v) => update('type_id', v)"
    />
    <BaseSelect
      :label="$t('inventory.roomNumber')"
      :placeholder="$t('common.any')"
      :model-value="modelValue.room_number_id"
      :options="roomNumbers.map((r) => ({ value: r.id, label: itemLabel(r) }))"
      @change="(v) => update('room_number_id', v)"
    />
    <BaseSelect
      :label="$t('inventory.contractType')"
      :placeholder="$t('common.any')"
      :model-value="modelValue.contract_type_id"
      :options="contractTypes.map((c) => ({ value: c.id, label: itemLabel(c) }))"
      @change="(v) => update('contract_type_id', v)"
    />
    <BaseSelect
      :label="$t('inventory.floor')"
      :placeholder="$t('common.any')"
      :model-value="modelValue.floor_id"
      :options="floors.map((f) => ({ value: f.id, label: itemLabel(f) }))"
      @change="(v) => update('floor_id', v)"
    />
    <BaseInput
      :model-value="modelValue.area_min"
      :label="$t('desire.areaMin')"
      type="number"
      @update:model-value="(v) => update('area_min', v)"
    />
    <BaseInput
      :model-value="modelValue.area_max"
      :label="$t('desire.areaMax')"
      type="number"
      @update:model-value="(v) => update('area_max', v)"
    />
    <BaseInput
      :model-value="modelValue.rooms_min"
      :label="$t('desire.roomsMin')"
      type="number"
      @update:model-value="(v) => update('rooms_min', v)"
    />
    <MoneyInput
      :model-value="modelValue.budget_min"
      :label="$t('desire.budgetMin')"
      @update:model-value="(v) => update('budget_min', v)"
    />
    <MoneyInput
      :model-value="modelValue.budget_max"
      :label="$t('desire.budgetMax')"
      @update:model-value="(v) => update('budget_max', v)"
    />
    <BaseTextarea
      class="sm:col-span-2"
      :label="$t('desire.notesLabel')"
      required
      :rows="2"
      :model-value="modelValue.notes"
      @update:model-value="(v) => update('notes', v)"
    />
  </div>
</template>
