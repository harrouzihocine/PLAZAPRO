<script setup>
import { computed, ref } from 'vue'
import { titleCaseName } from '@/utils/names'

const props = defineProps({
  label: { type: String, default: '' },
  type: { type: String, default: 'text' },
  modelValue: { type: [String, Number], default: '' },
  error: { type: String, default: '' },
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
    <span v-if="label" class="mb-1 block text-sm">{{ label }}</span>
    <div class="relative">
      <input
        :type="inputType"
        :value="modelValue"
        class="w-full rounded-token border border-border bg-bg px-3 py-2 min-h-[44px] text-ink outline-none focus:border-primary"
        :class="{ 'pr-11': isPassword }"
        @input="onInput"
      />
      <button
        v-if="isPassword"
        type="button"
        class="absolute inset-y-0 right-0 flex items-center px-3 text-ink opacity-60 hover:opacity-100 focus:opacity-100 outline-none"
        :aria-label="revealed ? 'Hide password' : 'Show password'"
        :aria-pressed="revealed"
        @click="revealed = !revealed"
      >
        <svg
          v-if="revealed"
          xmlns="http://www.w3.org/2000/svg"
          width="20"
          height="20"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          stroke-linecap="round"
          stroke-linejoin="round"
          aria-hidden="true"
        >
          <path d="M9.88 9.88a3 3 0 0 0 4.24 4.24" />
          <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68" />
          <path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61" />
          <line x1="2" x2="22" y1="2" y2="22" />
        </svg>
        <svg
          v-else
          xmlns="http://www.w3.org/2000/svg"
          width="20"
          height="20"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          stroke-linecap="round"
          stroke-linejoin="round"
          aria-hidden="true"
        >
          <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
          <circle cx="12" cy="12" r="3" />
        </svg>
      </button>
    </div>
    <span v-if="error" class="mt-1 block text-sm text-danger">{{ error }}</span>
  </label>
</template>
