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
import FilterPanel from '@/components/ui/FilterPanel.vue'
import { t } from '@/i18n'

const props = defineProps({
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
  { value: '', label: t('oversight.allUsers') },
  ...staff.value.map((u) => ({ value: u.id, label: u.name })),
])

const activeCount = computed(() => [props.from, props.to, props.userId].filter(Boolean).length)

function clear() {
  emit('update:from', '')
  emit('update:to', '')
  emit('update:userId', '')
  emit('apply')
}
</script>

<template>
  <FilterPanel card :active-count="activeCount" class="mb-4">
  <div class="flex flex-wrap items-end gap-2 max-sm:px-4 max-sm:py-3">
    <BaseInput
      :model-value="from"
      type="date"
:label="$t('oversight.from')"
      class="w-40"
      @update:model-value="emit('update:from', $event)"
    />
    <BaseInput
      :model-value="to"
      type="date"
:label="$t('oversight.to')"
      class="w-40"
      @update:model-value="emit('update:to', $event)"
    />
    <BaseSelect
      :model-value="userId"
:label="$t('oversight.user')"
      class="w-48"
      :options="userOptions"
      searchable="auto"
      @update:model-value="emit('update:userId', $event)"
    />
    <Button :label="$t('common.apply')" icon="pi pi-filter" size="small" @click="emit('apply')" />
    <Button v-if="from || to || userId" :label="$t('common.clear')" size="small" text severity="secondary" @click="clear" />
  </div>
  </FilterPanel>
</template>
