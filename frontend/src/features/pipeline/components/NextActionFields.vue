<script setup>
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
    <label class="block">
      <span class="mb-1 block text-xs">Type</span>
      <select :value="modelValue.type" :class="selectClass" @change="update('type', $event.target.value)">
        <option v-for="t in types" :key="t" :value="t">{{ t.replace('_', ' ') }}</option>
      </select>
    </label>
    <label class="block">
      <span class="mb-1 block text-xs">Due</span>
      <input
        type="datetime-local"
        :value="modelValue.due_at"
        :class="selectClass"
        @input="update('due_at', $event.target.value)"
      />
    </label>
    <label class="block">
      <span class="mb-1 block text-xs">Assign to</span>
      <select :value="modelValue.assigned_to" :class="selectClass" @change="update('assigned_to', $event.target.value)">
        <option value="">Select agent</option>
        <option v-for="a in agents" :key="a.id" :value="a.id">{{ a.name }}</option>
      </select>
    </label>
  </fieldset>
</template>
