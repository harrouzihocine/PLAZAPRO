<script setup>
import { computed } from 'vue'
import BaseSelect from '@/components/base/BaseSelect.vue'

// The enforced next-action fieldset, shared by the log-call and complete-visit
// forms. Emits a merged object so the parent owns the value (no prop mutation).
//
// Who: a call defaults server-side to the client's sales agent, so we hide the
// assignee for everything EXCEPT an in-site (field) visit — which must be
// handed to a field agent (is_agent), picked from `fieldAgents`.
// When: a required date + an OPTIONAL time (agents usually only know the day).
const props = defineProps({
  modelValue: { type: Object, required: true },
  fieldAgents: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:modelValue'])

const types = [
  { value: 'call', label: 'call' },
  { value: 'office_visit', label: 'office visit' },
  { value: 'in_site_visit', label: 'in-site visit' },
]

const inputClass =
  'w-full rounded-token border border-border bg-bg px-3 py-2 min-h-[44px] text-ink outline-none focus:border-primary'

const needsFieldAgent = computed(() => props.modelValue.type === 'in_site_visit')

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
      :options="types"
      @change="(v) => update('type', v)"
    />
    <label class="block">
      <span class="mb-1 block text-xs">Due date</span>
      <input
        type="date"
        :value="modelValue.due_date"
        :class="inputClass"
        @input="update('due_date', $event.target.value)"
      />
    </label>
    <label class="block">
      <span class="mb-1 block text-xs">Time <span class="opacity-50">(optional)</span></span>
      <input
        type="time"
        :value="modelValue.due_time"
        :class="inputClass"
        @input="update('due_time', $event.target.value)"
      />
    </label>
    <!-- Only in-site visits pick an assignee (a field agent); other types default
         to the client's sales agent on the server. -->
    <BaseSelect
      v-if="needsFieldAgent"
      class="sm:col-span-3"
      label="Assign to (field agent)"
      :model-value="modelValue.assigned_to"
      placeholder="Select field agent"
      :options="fieldAgents.map((a) => ({ value: a.id, label: a.name }))"
      @change="(v) => update('assigned_to', v)"
    />
  </fieldset>
</template>
