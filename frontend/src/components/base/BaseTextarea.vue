<script setup>
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
    <span v-if="label" class="mb-1 block text-sm">{{ label }}</span>
    <textarea
      :value="modelValue"
      :rows="rows"
      :placeholder="placeholder"
      class="w-full resize-y rounded-token border border-border bg-bg px-3 py-2 text-ink outline-none focus:border-primary"
      @input="emit('update:modelValue', $event.target.value)"
    />
    <span v-if="error" class="mt-1 block text-sm text-danger">{{ error }}</span>
  </label>
</template>
