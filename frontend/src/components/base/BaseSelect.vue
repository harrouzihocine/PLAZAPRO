<script setup>
import { computed } from 'vue'
import Select from 'primevue/select'
import { t } from '@/i18n'

// Searchable, clearable single-select on PrimeVue Select, keeping the historic
// contract: modelValue '' means none, options are { value, label, disabled? },
// and both `update:modelValue` and `change` fire with the new value.
const props = defineProps({
  label: { type: String, default: '' },
  // Shows a red "*" after the label. No star = the field is not required.
  required: { type: Boolean, default: false },
  modelValue: { type: [String, Number], default: '' },
  options: { type: Array, default: () => [] },
  placeholder: { type: String, default: null }, // null → localized "Select…"
  clearable: { type: Boolean, default: true },
  disabled: { type: Boolean, default: false },
  ariaLabel: { type: String, default: '' },
  // 'auto' shows the search box only once the list is long enough to need it.
  searchable: { type: [Boolean, String], default: 'auto' },
})
const emit = defineEmits(['update:modelValue', 'change'])

const placeholderText = computed(() => props.placeholder ?? t('common.selectEllipsis'))

const showFilter = computed(() =>
  props.searchable === 'auto' ? props.options.length > 6 : Boolean(props.searchable),
)

// '' (our "none") renders the placeholder; PrimeVue expects null for that.
const value = computed(() =>
  props.modelValue === '' || props.modelValue == null ? null : props.modelValue,
)

// Any option may carry an `icon` (a PrimeIcons class); when at least one does we
// render it inline in the list and the selected value. Looked up by value so the
// #value slot (which only receives the primitive) can show its icon too.
const hasIcons = computed(() => props.options.some((o) => o.icon))
const selectedOption = computed(() =>
  value.value == null ? null : props.options.find((o) => String(o.value) === String(value.value)),
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
    <span v-if="label" class="mb-1.5 block text-sm font-medium text-ink"
      >{{ label }}<span v-if="required" class="text-danger" aria-hidden="true"> *</span></span
    >
    <Select
      :model-value="value"
      :options="options"
      option-label="label"
      option-value="value"
      option-disabled="disabled"
      :placeholder="placeholderText"
      :disabled="disabled"
      :show-clear="clearable && value !== null"
      :filter="showFilter"
      :aria-label="ariaLabel || undefined"
      fluid
      @update:model-value="onChange"
    >
      <template v-if="hasIcons" #option="{ option }">
        <span class="flex items-center gap-2">
          <i v-if="option.icon" :class="option.icon" class="text-sm text-mute" aria-hidden="true" />
          <span>{{ option.label }}</span>
        </span>
      </template>
      <template v-if="hasIcons" #value="{ value: v }">
        <span v-if="selectedOption" class="flex items-center gap-2">
          <i
            v-if="selectedOption.icon"
            :class="selectedOption.icon"
            class="text-sm text-mute"
            aria-hidden="true"
          />
          <span>{{ selectedOption.label }}</span>
        </span>
        <span v-else class="text-mute">{{ v == null ? placeholderText : v }}</span>
      </template>
    </Select>
  </label>
</template>
