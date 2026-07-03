<script setup>
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import ToggleSwitch from 'primevue/toggleswitch'
import BaseInput from '@/components/base/BaseInput.vue'

const props = defineProps({
  item: { type: Object, required: true },
  isFirst: { type: Boolean, default: false },
  isLast: { type: Boolean, default: false },
})
const emit = defineEmits(['save', 'toggle', 'move'])

const label = ref(props.item.label)
const value = ref(props.item.value)

watch(
  () => props.item,
  (i) => {
    label.value = i.label
    value.value = i.value
  },
)

const dirty = computed(() => label.value !== props.item.label || value.value !== props.item.value)

function save() {
  if (dirty.value) emit('save', { label: label.value, value: value.value })
}
</script>

<template>
  <div
    class="flex flex-col gap-2 rounded-xl border border-line p-2.5 sm:flex-row sm:items-center"
    :class="{ 'opacity-60': !item.is_active }"
  >
    <div class="grid flex-1 grid-cols-1 gap-2 sm:grid-cols-2">
      <BaseInput v-model="label" aria-label="Label" />
      <BaseInput v-model="value" aria-label="Value" />
    </div>
    <div class="flex items-center gap-1.5">
      <Button v-if="dirty" label="Save" icon="pi pi-check" size="small" @click="save" />
      <Button
        icon="pi pi-arrow-up"
        text
        rounded
        size="small"
        severity="secondary"
        :disabled="isFirst"
        aria-label="Move up"
        @click="emit('move', -1)"
      />
      <Button
        icon="pi pi-arrow-down"
        text
        rounded
        size="small"
        severity="secondary"
        :disabled="isLast"
        aria-label="Move down"
        @click="emit('move', 1)"
      />
      <ToggleSwitch
        :model-value="Boolean(item.is_active)"
        :aria-label="item.is_active ? 'Deactivate item' : 'Activate item'"
        @update:model-value="emit('toggle')"
      />
    </div>
  </div>
</template>
