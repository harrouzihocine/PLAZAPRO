<script setup>
import { computed } from 'vue'
import Select from 'primevue/select'

// A 24-hour time field whose dropdown offers ONLY half-hour slots (00 and 30),
// yet stays editable so any other minute can still be typed by hand. Built on
// PrimeVue Select's `editable` combobox rather than a native <input type="time">
// because the native `step` attribute does not reliably restrict the minute list
// across browsers (it kept showing every minute). Value is an "HH:mm" string,
// matching what the API and the paired date inputs expect.
const props = defineProps({
  modelValue: { type: String, default: '' },
  placeholder: { type: String, default: '--:--' },
  ariaLabel: { type: String, default: '' },
  // The dropdown lists the working day first: it starts at `startHour` and wraps
  // the earlier (late-night) slots around to the very end. Default 07:00.
  startHour: { type: Number, default: 7 },
})
const emit = defineEmits(['update:modelValue'])

const options = computed(() => {
  const all = Array.from({ length: 48 }, (_, i) => {
    const h = String(Math.floor(i / 2)).padStart(2, '0')
    const m = i % 2 ? '30' : '00'
    return `${h}:${m}`
  })
  const offset = ((props.startHour % 24) + 24) % 24 * 2
  return [...all.slice(offset), ...all.slice(0, offset)]
})

const value = computed(() => props.modelValue || null)

function onChange(next) {
  emit('update:modelValue', next ?? '')
}
</script>

<template>
  <Select
    :model-value="value"
    :options="options"
    editable
    :placeholder="placeholder"
    :aria-label="ariaLabel || undefined"
    fluid
    @update:model-value="onChange"
  />
</template>
