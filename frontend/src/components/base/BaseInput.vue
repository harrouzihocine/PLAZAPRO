<script setup>
import { computed, ref } from 'vue'
import InputText from 'primevue/inputtext'
import { titleCaseName } from '@/utils/names'

const props = defineProps({
  label: { type: String, default: '' },
  type: { type: String, default: 'text' },
  modelValue: { type: [String, Number], default: '' },
  error: { type: String, default: '' },
  placeholder: { type: String, default: '' },
  // Standardize input to Title Case as the user types (used for name fields).
  capitalize: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const isPassword = computed(() => props.type === 'password')
const revealed = ref(false)
const inputType = computed(() =>
  isPassword.value ? (revealed.value ? 'text' : 'password') : props.type,
)

function onInput(event) {
  let value = event.target.value
  if (props.capitalize) {
    const normalized = titleCaseName(value)
    if (normalized !== value) {
      // Rewrite the field and keep the caret at the end (typical while typing).
      event.target.value = normalized
      value = normalized
    }
  }
  emit('update:modelValue', value)
}
</script>

<template>
  <label class="block">
    <span v-if="label" class="mb-1.5 block text-sm font-medium text-ink">{{ label }}</span>
    <div class="relative">
      <InputText
        :type="inputType"
        :model-value="String(modelValue ?? '')"
        :placeholder="placeholder || undefined"
        :invalid="Boolean(error)"
        fluid
        :class="{ '!pr-11': isPassword }"
        @input="onInput"
      />
      <button
        v-if="isPassword"
        type="button"
        class="absolute inset-y-0 right-0 flex items-center px-3 text-mute hover:text-ink"
        :aria-label="revealed ? 'Hide password' : 'Show password'"
        :aria-pressed="revealed"
        @click="revealed = !revealed"
      >
        <i :class="revealed ? 'pi pi-eye-slash' : 'pi pi-eye'" aria-hidden="true" />
      </button>
    </div>
    <span v-if="error" class="mt-1 block text-sm text-danger">{{ error }}</span>
  </label>
</template>
