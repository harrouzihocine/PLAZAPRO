<script setup>
import { watch } from 'vue'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'
import { useDynamicList } from '@/composables/useDynamicList'
import { useWilayas, useCommunes } from '@/composables/useGeography'

// The desire-profile fieldset (Branch A — no matching inventory), shared by the
// call-log form and the shift-to-desire modal. Emits a merged object so the parent
// owns the value: { wilaya_id, commune_id, type_id, floor_pref, budget_min, budget_max, notes }.
const props = defineProps({ modelValue: { type: Object, required: true } })
const emit = defineEmits(['update:modelValue'])

const { wilayas } = useWilayas()
const { communes, load: loadCommunes } = useCommunes()
const { items: unitTypes } = useDynamicList('unit_types')

function update(field, value) {
  emit('update:modelValue', { ...props.modelValue, [field]: value })
}

// A USER wilaya change clears the picked commune (the list is about to change);
// programmatic fills (loading a saved desire) go through the watcher below, which
// only loads the commune list and keeps the saved commune selected.
function updateWilaya(value) {
  emit('update:modelValue', { ...props.modelValue, wilaya_id: value, commune_id: '' })
}

watch(() => props.modelValue.wilaya_id, (id) => loadCommunes(id), { immediate: true })
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
    <BaseSelect
      label="Type"
      placeholder="Any"
      :model-value="modelValue.type_id"
      :options="unitTypes.map((t) => ({ value: t.id, label: t.label }))"
      @change="(v) => update('type_id', v)"
    />
    <BaseInput :model-value="modelValue.floor_pref" label="Floor preference" @update:model-value="(v) => update('floor_pref', v)" />
    <BaseInput :model-value="modelValue.budget_min" label="Budget min" type="number" @update:model-value="(v) => update('budget_min', v)" />
    <BaseInput :model-value="modelValue.budget_max" label="Budget max" type="number" @update:model-value="(v) => update('budget_max', v)" />
    <BaseTextarea
      class="sm:col-span-2"
      label="Notes"
      :rows="2"
      :model-value="modelValue.notes"
      @update:model-value="(v) => update('notes', v)"
    />
  </div>
</template>
