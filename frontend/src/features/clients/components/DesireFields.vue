<script setup>
import { computed, onMounted, watch } from 'vue'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseMultiSelect from '@/components/base/BaseMultiSelect.vue'
import MoneyInput from '@/components/base/MoneyInput.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'
import { useDynamicList, itemLabel } from '@/composables/useDynamicList'
import { useWilayas, useCommunesByWilayas } from '@/composables/useGeography'
import { useLocationsStore } from '@/features/inventory/locationsStore'

// The desire-profile fieldset (Branch A — no matching inventory), shared by the
// call-log form and the shift-to-desire modal. Max detail, mirroring the unit
// form — but every selector is MULTI-valued ("F2 OR F3", "Hydra OR Kouba"):
// empty selection = no preference. Notes are REQUIRED — the story behind the
// numbers. Emits a merged object so the parent owns the value: { wilaya_ids,
// commune_ids, type_ids, room_number_ids, contract_type_ids, floor_ids,
// area_min, area_max, budget_min, budget_max, location_ids, notes }.
const props = defineProps({ modelValue: { type: Object, required: true } })
const emit = defineEmits(['update:modelValue'])

const { wilayas } = useWilayas()
const { communes, load: loadCommunes } = useCommunesByWilayas()
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

// A USER wilaya change prunes picked communes down to the wilayas still
// selected (the option list is about to shrink); programmatic fills (loading a
// saved desire) go through the watcher below, which only loads the commune
// union and keeps the saved communes selected.
async function updateWilayas(value) {
  const loaded = await loadCommunes(value)
  const keep = new Set(loaded.map((c) => c.id))
  emit('update:modelValue', {
    ...props.modelValue,
    wilaya_ids: value,
    commune_ids: (props.modelValue.commune_ids ?? []).filter((id) => keep.has(id)),
  })
}

watch(
  () => props.modelValue.wilaya_ids,
  (ids) => loadCommunes(ids),
  { immediate: true, deep: true },
)

// Several wilayas selected → disambiguate homonym communes with their wilaya.
const wilayaName = (id) => wilayas.value.find((w) => w.id === id)?.name ?? ''
const communeOptions = computed(() =>
  communes.value.map((c) => ({
    value: c.id,
    label:
      (props.modelValue.wilaya_ids ?? []).length > 1
        ? `${c.name} — ${wilayaName(c.wilaya_id)}`
        : c.name,
  })),
)
</script>

<template>
  <div class="grid gap-3 sm:grid-cols-2">
    <BaseMultiSelect
      :label="$t('geo.wilaya')"
      :placeholder="$t('common.any')"
      :model-value="modelValue.wilaya_ids ?? []"
      :options="wilayas.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))"
      @update:model-value="updateWilayas"
    />
    <BaseMultiSelect
      :label="$t('geo.commune')"
      :placeholder="$t('common.any')"
      :disabled="!(modelValue.wilaya_ids ?? []).length"
      :model-value="modelValue.commune_ids ?? []"
      :options="communeOptions"
      @update:model-value="(v) => update('commune_ids', v)"
    />
    <BaseMultiSelect
      class="sm:col-span-2"
      :label="$t('desire.preferredSites')"
      :placeholder="$t('desire.anySite')"
      :model-value="modelValue.location_ids ?? []"
      :options="locations.items.map((l) => ({ value: l.id, label: l.name }))"
      @update:model-value="(v) => update('location_ids', v)"
    />
    <BaseMultiSelect
      :label="$t('inventory.projectType')"
      :placeholder="$t('common.any')"
      :model-value="modelValue.type_ids ?? []"
      :options="projectTypes.map((t) => ({ value: t.id, label: itemLabel(t) }))"
      @update:model-value="(v) => update('type_ids', v)"
    />
    <BaseMultiSelect
      :label="$t('inventory.roomNumber')"
      :placeholder="$t('common.any')"
      :model-value="modelValue.room_number_ids ?? []"
      :options="roomNumbers.map((r) => ({ value: r.id, label: itemLabel(r) }))"
      @update:model-value="(v) => update('room_number_ids', v)"
    />
    <BaseMultiSelect
      :label="$t('inventory.contractType')"
      :placeholder="$t('common.any')"
      :model-value="modelValue.contract_type_ids ?? []"
      :options="contractTypes.map((c) => ({ value: c.id, label: itemLabel(c) }))"
      @update:model-value="(v) => update('contract_type_ids', v)"
    />
    <BaseMultiSelect
      :label="$t('inventory.floor')"
      :placeholder="$t('common.any')"
      :model-value="modelValue.floor_ids ?? []"
      :options="floors.map((f) => ({ value: f.id, label: itemLabel(f) }))"
      @update:model-value="(v) => update('floor_ids', v)"
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
