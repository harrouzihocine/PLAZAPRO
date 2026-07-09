<script setup>
import { computed } from 'vue'
import MultiSelect from 'primevue/multiselect'
import { t } from '@/i18n'

// Multi-value filter select on PrimeVue MultiSelect, keeping the historic
// contract: modelValue is an array of option values, options are
// { value, label }, and the trigger summarises the selection.
const props = defineProps({
  label: { type: String, default: '' },
  // Shows a red "*" after the label. No star = the field is not required.
  required: { type: Boolean, default: false },
  modelValue: { type: Array, default: () => [] },
  options: { type: Array, default: () => [] },
  placeholder: { type: String, default: null }, // null → localized "All"
  // 'auto' shows the search box only once the list is long enough to need it.
  searchable: { type: [Boolean, String], default: 'auto' },
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const placeholderText = computed(() => props.placeholder ?? t('common.all'))

const showFilter = computed(() =>
  props.searchable === 'auto' ? props.options.length > 6 : Boolean(props.searchable),
)
</script>

<template>
  <label class="block">
    <span v-if="label" class="mb-1.5 block text-sm font-medium text-ink"
      >{{ label }}<span v-if="required" class="text-danger" aria-hidden="true"> *</span></span
    >
    <MultiSelect
      :model-value="modelValue"
      :options="options"
      option-label="label"
      option-value="value"
      :placeholder="placeholderText"
      :disabled="disabled"
      :filter="showFilter"
      :max-selected-labels="1"
      selected-items-label="{0} selected"
      show-clear
      fluid
      @update:model-value="emit('update:modelValue', $event ?? [])"
    />
  </label>
</template>
