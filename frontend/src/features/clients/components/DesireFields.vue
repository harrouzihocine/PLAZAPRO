<script setup>
import { onMounted, watch } from 'vue'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseMultiSelect from '@/components/base/BaseMultiSelect.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'
import { useDynamicList } from '@/composables/useDynamicList'
import { useWilayas, useCommunes } from '@/composables/useGeography'
import { useLocationsStore } from '@/features/inventory/locationsStore'

// The desire-profile fieldset (Branch A — no matching inventory), shared by the
// call-log form and the shift-to-desire modal. Max detail, mirroring the unit
// form: type + floor selectors, area and budget ranges, preferred sites.
// Notes are REQUIRED — the story behind the numbers. Emits a merged object so
// the parent owns the value: { wilaya_id, commune_id, type_id, floor_id,
// area_min, area_max, rooms_min, budget_min, budget_max, location_ids, notes }.
const props = defineProps({ modelValue: { type: Object, required: true } })
const emit = defineEmits(['update:modelValue'])

const { wilayas } = useWilayas()
const { communes, load: loadCommunes } = useCommunes()
const { items: unitTypes } = useDynamicList('unit_types')
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
      label="Wilaya"
      placeholder="Any"
      :model-value="modelValue.wilaya_id"
      :options="wilayas.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))"
      @change="updateWilaya"
    />
    <BaseSelect
      label="Commune"
      placeholder="Any"
      :disabled="!modelValue.wilaya_id"
      :model-value="modelValue.commune_id"
      :options="communes.map((c) => ({ value: c.id, label: c.name }))"
      @change="(v) => update('commune_id', v)"
    />
    <BaseMultiSelect
      class="sm:col-span-2"
      label="Preferred sites (projects)"
      placeholder="Any site"
      :model-value="modelValue.location_ids ?? []"
      :options="locations.items.map((l) => ({ value: l.id, label: l.name }))"
      @update:model-value="(v) => update('location_ids', v)"
    />
    <BaseSelect
      label="Type"
      placeholder="Any"
      :model-value="modelValue.type_id"
      :options="unitTypes.map((t) => ({ value: t.id, label: t.label }))"
      @change="(v) => update('type_id', v)"
    />
    <BaseSelect
      label="Floor"
      placeholder="Any"
      :model-value="modelValue.floor_id"
      :options="floors.map((f) => ({ value: f.id, label: f.label }))"
      @change="(v) => update('floor_id', v)"
    />
    <BaseInput
      :model-value="modelValue.area_min"
      label="Area min (m²)"
      type="number"
      @update:model-value="(v) => update('area_min', v)"
    />
    <BaseInput
      :model-value="modelValue.area_max"
      label="Area max (m²)"
      type="number"
      @update:model-value="(v) => update('area_max', v)"
    />
    <BaseInput
      :model-value="modelValue.rooms_min"
      label="Rooms (minimum)"
      type="number"
      @update:model-value="(v) => update('rooms_min', v)"
    />
    <BaseInput
      :model-value="modelValue.budget_min"
      label="Budget min"
      type="number"
      @update:model-value="(v) => update('budget_min', v)"
    />
    <BaseInput
      :model-value="modelValue.budget_max"
      label="Budget max"
      type="number"
      @update:model-value="(v) => update('budget_max', v)"
    />
    <BaseTextarea
      class="sm:col-span-2"
      label="Notes (required — what exactly are they after?)"
      :rows="2"
      :model-value="modelValue.notes"
      @update:model-value="(v) => update('notes', v)"
    />
  </div>
</template>
