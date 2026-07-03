<script setup>
import Textarea from 'primevue/textarea'

// The multi-line sibling of BaseInput — same tokens, same label wrapper. Every
// free-text "notes"-style field in the app uses this (house rule: notes are
// textareas, never single-line inputs).
defineProps({
  label: { type: String, default: '' },
  modelValue: { type: [String, Number], default: '' },
  error: { type: String, default: '' },
  rows: { type: Number, default: 3 },
  placeholder: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])
</script>

<template>
  <label class="block">
    <span v-if="label" class="mb-1.5 block text-sm font-medium text-ink">{{ label }}</span>
    <Textarea
      :model-value="String(modelValue ?? '')"
      :rows="rows"
      :placeholder="placeholder || undefined"
      :invalid="Boolean(error)"
      auto-resize
      fluid
      @update:model-value="emit('update:modelValue', $event)"
    />
    <span v-if="error" class="mt-1 block text-sm text-danger">{{ error }}</span>
  </label>
</template>
