<script setup>
import { computed } from 'vue'
import Select from 'primevue/select'

// Searchable, clearable single-select on PrimeVue Select, keeping the historic
// contract: modelValue '' means none, options are { value, label, disabled? },
// and both `update:modelValue` and `change` fire with the new value.
const props = defineProps({
  label: { type: String, default: '' },
  modelValue: { type: [String, Number], default: '' },
  options: { type: Array, default: () => [] },
  placeholder: { type: String, default: 'Select…' },
  clearable: { type: Boolean, default: true },
  disabled: { type: Boolean, default: false },
  ariaLabel: { type: String, default: '' },
  // 'auto' shows the search box only once the list is long enough to need it.
  searchable: { type: [Boolean, String], default: 'auto' },
})
const emit = defineEmits(['update:modelValue', 'change'])

const showFilter = computed(() =>
  props.searchable === 'auto' ? props.options.length > 6 : Boolean(props.searchable),
)

// '' (our "none") renders the placeholder; PrimeVue expects null for that.
const value = computed(() =>
  props.modelValue === '' || props.modelValue == null ? null : props.modelValue,
)

function onChange(next) {
  const out = next ?? ''
  if (String(out) !== String(props.modelValue)) {
    emit('update:modelValue', out)
    emit('change', out)
  }
}
</script>

<template>
  <label class="block">
    <span v-if="label" class="mb-1.5 block text-sm font-medium text-ink">{{ label }}</span>
    <Select
      :model-value="value"
      :options="options"
      option-label="label"
      option-value="value"
      option-disabled="disabled"
      :placeholder="placeholder"
      :disabled="disabled"
      :show-clear="clearable && value !== null"
      :filter="showFilter"
      :aria-label="ariaLabel || undefined"
      fluid
      @update:model-value="onChange"
    />
  </label>
</template>
