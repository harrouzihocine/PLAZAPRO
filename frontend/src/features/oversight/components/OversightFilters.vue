<script setup>
// Shared filter bar for the oversight monitors. Two-way binds from/to (ISO
// dates) and userId (creator on most monitors, assignee on overdue actions —
// BuildOversight picks the right column per query) and asks the parent to
// re-fetch on Apply / Clear.
import { computed, onMounted, ref } from 'vue'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import Button from 'primevue/button'
import { staffApi } from '@/features/clients/api'

defineProps({
  from: { type: String, default: '' },
  to: { type: String, default: '' },
  userId: { type: [String, Number], default: '' },
})
const emit = defineEmits(['update:from', 'update:to', 'update:userId', 'apply'])

const staff = ref([])
onMounted(async () => {
  try {
    staff.value = await staffApi.list()
  } catch {
    /* the user filter is best-effort; from/to still work without it */
  }
})
const userOptions = computed(() => [
  { value: '', label: 'All users' },
  ...staff.value.map((u) => ({ value: u.id, label: u.name })),
])

function clear() {
  emit('update:from', '')
  emit('update:to', '')
  emit('update:userId', '')
  emit('apply')
}
</script>

<template>
  <div class="mb-4 flex flex-wrap items-end gap-2">
    <BaseInput
      :model-value="from"
      type="date"
      label="From"
      class="w-40"
      @update:model-value="emit('update:from', $event)"
    />
    <BaseInput
      :model-value="to"
      type="date"
      label="To"
      class="w-40"
      @update:model-value="emit('update:to', $event)"
    />
    <BaseSelect
      :model-value="userId"
      label="User"
      class="w-48"
      :options="userOptions"
      searchable="auto"
      @update:model-value="emit('update:userId', $event)"
    />
    <Button label="Apply" icon="pi pi-filter" size="small" @click="emit('apply')" />
    <Button v-if="from || to || userId" label="Clear" size="small" text severity="secondary" @click="clear" />
  </div>
</template>
