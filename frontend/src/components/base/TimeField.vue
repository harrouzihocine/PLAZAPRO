<script setup>
import { computed } from 'vue'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import { useNativePhone } from '@/composables/useNativeMode'

// A 24-hour time field, rendered two ways (owner decision, 2026-07-08):
//
// - Phone APK: the platform's standard time dialog via <input type="time"> —
//   phones get NO dropdown at all. Any in-page list fights the soft keyboard
//   and the small viewport; the native dialog is modal, keyboard-free, and
//   what users expect from an app.
// - Everywhere else (web, tablets): a non-editable Select listing ONLY
//   half-hour slots (00/30) — the native `step` attribute can't reliably
//   restrict minutes across desktop browsers, which is why the list exists.
//   Non-editable because a text input here pops the soft keyboard over the
//   open list on touch screens; the clear icon unsets an optional time.
//
// Value is an "HH:mm" string either way, matching what the API and the paired
// date inputs expect.
const props = defineProps({
  modelValue: { type: String, default: '' },
  placeholder: { type: String, default: '--:--' },
  ariaLabel: { type: String, default: '' },
  // The dropdown lists the working day first: it starts at `startHour` and wraps
  // the earlier (late-night) slots around to the very end. Default 07:00.
  startHour: { type: Number, default: 7 },
})
const emit = defineEmits(['update:modelValue'])

const nativePhone = useNativePhone()

const options = computed(() => {
  const all = Array.from({ length: 48 }, (_, i) => {
    const h = String(Math.floor(i / 2)).padStart(2, '0')
    const m = i % 2 ? '30' : '00'
    return `${h}:${m}`
  })
  const offset = ((props.startHour % 24) + 24) % 24 * 2
  const list = [...all.slice(offset), ...all.slice(0, offset)]
  // A value off the half-hour grid (a "now" prefill, or set through the native
  // dialog on a phone) would otherwise render as blank — a Select only displays
  // values it lists. Slot it in at its chronological place.
  const v = props.modelValue
  if (v && !list.includes(v)) {
    const minutesFromStart = (t) => {
      const [h = 0, m = 0] = t.split(':').map(Number)
      return ((((h - props.startHour) % 24) + 24) % 24) * 60 + m
    }
    const at = list.findIndex((t) => minutesFromStart(t) > minutesFromStart(v))
    list.splice(at === -1 ? list.length : at, 0, v)
  }
  return list
})

const value = computed(() => props.modelValue || null)

function onChange(next) {
  emit('update:modelValue', next ?? '')
}
</script>

<template>
  <InputText
    v-if="nativePhone"
    type="time"
    step="1800"
    :model-value="modelValue"
    :aria-label="ariaLabel || undefined"
    fluid
    @input="onChange($event.target.value)"
  />
  <Select
    v-else
    :model-value="value"
    :options="options"
    :show-clear="value !== null"
    :placeholder="placeholder"
    :aria-label="ariaLabel || undefined"
    fluid
    @update:model-value="onChange"
  />
</template>
