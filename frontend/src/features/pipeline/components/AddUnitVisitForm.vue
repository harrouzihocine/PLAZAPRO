<script setup>
import { computed, ref } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import TimeField from '@/components/base/TimeField.vue'
import ProjectUnitsPicker from '@/features/inventory/components/ProjectUnitsPicker.vue'
import { useAuthStore } from '@/features/settings/store'
import { confirmAction } from '@/composables/useConfirm'
import { todayInput } from '@/utils/format'
import { t } from '@/i18n'

// "Add unit to visit" — the standalone twin of the "another apartment" step in
// visit completion, freed from its "only on the last open visit" gate. Pick the
// apartment(s) from the property picker, set a date (+ optional time); it goes
// out as a pending in-site visit. Dispatchers may pre-pick the field agent;
// everyone else's addition lands in the dispatch pool. Emits the ready payload;
// the parent submits.
defineProps({
  fieldAgents: { type: Array, default: () => [] },
  saving: { type: Boolean, default: false },
})
const emit = defineEmits(['submit', 'cancel'])
const auth = useAuthStore()

const inputClass =
  'w-full rounded-md border border-line bg-card px-3 py-2 min-h-[42px] text-sm text-ink outline-none transition-colors focus:border-primary'

// A visit is always scheduled from today forward — never the past.
const today = todayInput()

const picks = ref([]) // ProjectUnitsPicker v-model (units-only)
const dueDate = ref('')
const dueTime = ref('')
const assignedTo = ref('')

const canDispatch = computed(() => auth.can('visits.dispatch'))

const unitIds = computed(() =>
  picks.value.filter((p) => p.shortlistable_type === 'unit').map((p) => p.shortlistable_id),
)
const ready = computed(() => unitIds.value.length > 0 && !!dueDate.value)

// Wipe everything the agent picked or typed, back to a blank form.
async function reset() {
  if (!(await confirmAction({ text: t('common.resetFormConfirm') }))) return
  picks.value = []
  dueDate.value = ''
  dueTime.value = ''
  assignedTo.value = ''
}

function submit() {
  if (!ready.value) return
  emit('submit', {
    unit_ids: unitIds.value,
    due_date: dueDate.value,
    due_time: dueTime.value || null,
    // Only dispatchers can pre-assign; the server ignores it otherwise.
    assigned_to: canDispatch.value ? assignedTo.value || null : null,
  })
}
</script>

<template>
  <form class="space-y-4" @submit.prevent="submit">
    <p class="flex items-center gap-2 text-sm text-mute">
      <i class="pi pi-map-marker" aria-hidden="true" />
      {{ $t('pipeline.addUnitIntro') }}
    </p>

    <ProjectUnitsPicker v-model="picks" units-only />

    <div class="grid gap-3 sm:grid-cols-2">
      <label class="block">
        <span class="mb-1.5 block text-sm font-medium text-ink"
          >{{ $t('common.date') }}<span class="text-danger" aria-hidden="true"> *</span></span
        >
        <input v-model="dueDate" type="date" :min="today" :class="inputClass" />
      </label>
      <div class="block">
        <span class="mb-1.5 block text-sm font-medium text-ink">
          {{ $t('common.time') }}
        </span>
        <TimeField v-model="dueTime" :aria-label="$t('common.time')" />
      </div>
    </div>

    <!-- Dispatchers may pick the field agent right away (or leave it for the
         board); everyone else's addition goes to the dispatch pool. -->
    <BaseSelect
      v-if="canDispatch"
      v-model="assignedTo"
:label="$t('pipeline.assignToField')"
      :placeholder="$t('pipeline.decideLater')"
      :options="fieldAgents.map((a) => ({ value: a.id, label: a.name }))"
    />
    <p v-else class="flex items-center gap-2 text-sm text-mute">
      <i class="pi pi-send" aria-hidden="true" />
      {{ $t('pipeline.dispatcherNotified') }}
    </p>

    <div class="flex gap-2 pt-1">
      <BaseButton type="submit" :disabled="saving || !ready">{{ $t('pipeline.addToVisits') }}</BaseButton>
      <BaseButton type="button" variant="ghost" @click="emit('cancel')">{{ $t('common.cancel') }}</BaseButton>
      <BaseButton type="button" variant="ghost" class="ms-auto" :disabled="saving" @click="reset">
        <i class="pi pi-refresh text-[11px]" aria-hidden="true" /> {{ $t('common.reset') }}
      </BaseButton>
    </div>
  </form>
</template>
