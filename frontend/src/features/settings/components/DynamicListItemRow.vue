<script setup>
import { computed, ref, watch } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
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
    class="flex flex-col gap-2 rounded-token border border-border p-2 sm:flex-row sm:items-center"
    :class="{ 'opacity-60': !item.is_active }"
  >
    <div class="grid flex-1 grid-cols-1 gap-2 sm:grid-cols-2">
      <BaseInput v-model="label" aria-label="Label" />
      <BaseInput v-model="value" aria-label="Value" />
    </div>
    <div class="flex items-center gap-1">
      <BaseButton v-if="dirty" @click="save">Save</BaseButton>
      <BaseButton
        variant="ghost"
        :disabled="isFirst"
        aria-label="Move up"
        @click="emit('move', -1)"
      >
        ↑
      </BaseButton>
      <BaseButton
        variant="ghost"
        :disabled="isLast"
        aria-label="Move down"
        @click="emit('move', 1)"
      >
        ↓
      </BaseButton>
      <BaseButton variant="ghost" @click="emit('toggle')">
        {{ item.is_active ? 'On' : 'Off' }}
      </BaseButton>
    </div>
  </div>
</template>
