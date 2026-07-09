<script setup>
import { computed, onMounted, ref } from 'vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import { analyticsApi } from '@/features/analytics/api'
import { t } from '@/i18n'

// The command center's global filter bar: a period preset row + custom range +
// the three dimension selects (development, room type, agent). Owns the shared
// filter object via v-model; every change re-emits so the parent reloads the
// active tab. Reference data (locations/types/agents) is fetched once here.
const props = defineProps({
  modelValue: { type: Object, required: true },
})
const emit = defineEmits(['update:modelValue', 'change'])

const PERIODS = ['today', 'week', 'month', 'quarter', 'year', 'custom']

const refs = ref({ locations: [], unit_types: [], agents: [] })

const locationOptions = computed(() => [
  { value: '', label: t('kpi.allDevelopments') },
  ...refs.value.locations.map((l) => ({ value: l.id, label: l.name })),
])
const typeOptions = computed(() => [
  { value: '', label: t('kpi.allTypes') },
  ...refs.value.unit_types.map((u) => ({ value: u.id, label: u.label })),
])
const agentOptions = computed(() => [
  { value: '', label: t('kpi.allAgents') },
  ...refs.value.agents.map((a) => ({ value: a.id, label: a.name })),
])

function patch(next) {
  emit('update:modelValue', { ...props.modelValue, ...next })
  emit('change')
}
function setPeriod(p) {
  patch({ period: p })
}

onMounted(async () => {
  try {
    refs.value = await analyticsApi.kpiFilters()
  } catch {
    /* selects fall back to the "all" option only */
  }
})
</script>

<template>
  <div class="rounded-xl border border-line bg-card p-3 shadow-card">
    <div class="flex flex-wrap items-center gap-3">
      <!-- Period presets -->
      <div class="inline-flex flex-wrap gap-1 rounded-lg bg-surface-100 p-1 dark:bg-surface-800">
        <button
          v-for="p in PERIODS"
          :key="p"
          type="button"
          class="rounded-md px-3 py-1.5 text-sm transition-colors"
          :class="
            modelValue.period === p
              ? 'bg-card font-semibold text-ink shadow-sm'
              : 'text-mute hover:text-ink'
          "
          @click="setPeriod(p)"
        >
          {{ t(`kpi.period_${p}`) }}
        </button>
      </div>

      <!-- Custom range -->
      <template v-if="modelValue.period === 'custom'">
        <BaseInput
          :model-value="modelValue.from"
          type="date"
          :label="t('kpi.from')"
          class="w-40"
          @update:model-value="patch({ from: $event })"
        />
        <BaseInput
          :model-value="modelValue.to"
          type="date"
          :label="t('kpi.to')"
          class="w-40"
          @update:model-value="patch({ to: $event })"
        />
      </template>

      <div class="ms-auto flex flex-wrap items-end gap-2">
        <BaseSelect
          :model-value="modelValue.location_id"
          :options="locationOptions"
          searchable="auto"
          class="w-44"
          @update:model-value="patch({ location_id: $event })"
        />
        <BaseSelect
          :model-value="modelValue.unit_type"
          :options="typeOptions"
          class="w-36"
          @update:model-value="patch({ unit_type: $event })"
        />
        <BaseSelect
          :model-value="modelValue.agent_id"
          :options="agentOptions"
          searchable="auto"
          class="w-44"
          @update:model-value="patch({ agent_id: $event })"
        />
      </div>
    </div>
  </div>
</template>
