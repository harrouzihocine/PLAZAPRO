<script setup>
// A money field entered in the Algerian "Mil" unit (1 Mil = 10 000 DZD) while
// its v-model stays in base DZD — so call sites bind and submit the exact same
// base-DZD value they always have; only the on-screen number is scaled. The
// untouched value is preserved to the DZD; it is only recomputed on edit.
import { ref, watch } from 'vue'
import InputText from 'primevue/inputtext'
import { dzdToMil, milToDzd, MIL_LABEL } from '@/features/payments/money'

const props = defineProps({
  label: { type: String, default: '' },
  // Shows a red "*" after the label. No star = the field is not required.
  required: { type: Boolean, default: false },
  // Base DZD (decimal string or number), as stored/sent by the API.
  modelValue: { type: [String, Number, null], default: null },
  error: { type: String, default: '' },
  placeholder: { type: String, default: 'e.g. 100' },
})
const emit = defineEmits(['update:modelValue'])

// Local Mil text so mid-entry values ("1.") aren't reformatted under the caret.
const inner = ref(milText(props.modelValue))

function milText(dzd) {
  const mil = dzdToMil(dzd)
  return mil === null ? '' : String(mil)
}
function normalized(dzd) {
  return dzd === '' || dzd === null || dzd === undefined ? null : Number(dzd).toFixed(2)
}

// Re-sync only when the model changes from OUTSIDE (not our own emit echo).
watch(
  () => props.modelValue,
  (dzd) => {
    if (milToDzd(inner.value) !== normalized(dzd)) inner.value = milText(dzd)
  },
)

function onInput(event) {
  const val = event.target.value
  inner.value = val
  emit('update:modelValue', val === '' ? null : milToDzd(val))
}
</script>

<template>
  <label class="block">
    <span v-if="label" class="mb-1.5 block text-sm font-medium text-ink"
      >{{ label }}<span v-if="required" class="text-danger" aria-hidden="true"> *</span></span
    >
    <div class="relative">
      <InputText
        type="number"
        inputmode="decimal"
        :model-value="inner"
        :placeholder="placeholder || undefined"
        :invalid="Boolean(error)"
        fluid
        class="!pe-20"
        @input="onInput"
      />
      <span
        class="pointer-events-none absolute inset-y-0 end-0 flex items-center px-3 text-sm text-mute"
        >{{ MIL_LABEL }} DZD</span
      >
    </div>
    <span v-if="error" class="mt-1 block text-sm text-danger">{{ error }}</span>
  </label>
</template>
