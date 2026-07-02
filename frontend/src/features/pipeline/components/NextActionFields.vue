<script setup>
import BaseSelect from '@/components/base/BaseSelect.vue'

// The enforced next-action fieldset, shared by the log-call and complete-visit
// forms. Emits a merged object so the parent owns the value (no prop mutation).
const props = defineProps({
  modelValue: { type: Object, required: true },
  agents: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:modelValue'])

const types = ['call', 'office_visit', 'apartment_visit', 'follow_up', 'send_docs']
const selectClass =
  'w-full rounded-token border border-border bg-bg px-3 py-2 min-h-[44px] text-ink outline-none focus:border-primary'

function update(field, value) {
  emit('update:modelValue', { ...props.modelValue, [field]: value })
}
</script>

<template>
  <fieldset class="grid gap-2 rounded-token border border-border p-2 sm:grid-cols-3">
    <legend class="px-1 text-xs uppercase opacity-60">Next action (required)</legend>
    <BaseSelect
      label="Type"
      :model-value="modelValue.type"
      :clearable="false"
      :options="types.map((t) => ({ value: t, label: t.replace('_', ' ') }))"
      @change="(v) => update('type', v)"
    />
    <label class="block">
      <span class="mb-1 block text-xs">Due</span>
      <input
        type="datetime-local"
        :value="modelValue.due_at"
        :class="selectClass"
        @input="update('due_at', $event.target.value)"
      />
    </label>
    <BaseSelect
      label="Assign to"
      :model-value="modelValue.assigned_to"
      placeholder="Select agent"
      :options="agents.map((a) => ({ value: a.id, label: a.name }))"
      @change="(v) => update('assigned_to', v)"
    />
  </fieldset>
</template>
