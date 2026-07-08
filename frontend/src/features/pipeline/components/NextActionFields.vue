<script setup>
import { computed, onMounted, ref } from 'vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import TimeField from '@/components/base/TimeField.vue'
import ProjectUnitsPicker from '@/features/inventory/components/ProjectUnitsPicker.vue'
import AgentAgendaStrip from '@/features/pipeline/components/AgentAgendaStrip.vue'
import { useAuthStore } from '@/features/settings/store'
import { todayInput, unitLine } from '@/utils/format'
import { t } from '@/i18n'

// The next-action fieldset, shared by the log-call and complete-visit forms.
// Emits a merged object so the parent owns the value (no prop mutation).
//
// Who: a call defaults server-side to the client's sales agent, so we hide the
// assignee for everything EXCEPT an in-site (field) visit. Field agents are
// picked by visits.dispatch holders — either right here (optional) or later
// from the dispatch board; anyone else's in-site plan lands in the pending
// pool and the dispatchers are notified.
// When: a required date + an OPTIONAL time (agents usually only know the day).
//
// Which apartment (in-site only): when this fieldset concludes an in-site visit
// (`currentUnit` given), the agent says whether the next field visit is for the
// SAME apartment (a second look at this unit) or ANOTHER one — picked from the
// full property picker, exactly like the office/call flow. The chosen unit ids
// ride out as `modelValue.unit_ids`; the server visits only them.
const props = defineProps({
  modelValue: { type: Object, required: true },
  fieldAgents: { type: Array, default: () => [] },
  // The apartment the concluding in-site visit was for (enables same/another).
  currentUnit: { type: Object, default: null },
})
const emit = defineEmits(['update:modelValue'])
const auth = useAuthStore()

const types = computed(() => [
  { value: 'call', label: t('pipeline.typeCall') },
  { value: 'office_visit', label: t('status.office_visit') },
  { value: 'in_site_visit', label: t('status.in_site_visit') },
])

const inputClass =
  'w-full rounded-md border border-line bg-card px-3 py-2 min-h-[42px] text-sm text-ink outline-none transition-colors focus:border-primary'

// A next action is always scheduled from today forward — never the past.
const today = todayInput()

const isInSite = computed(() => props.modelValue.type === 'in_site_visit')
const canDispatch = computed(() => auth.can('visits.dispatch'))

// The free/busy strip helps the OWNER of the plan pick a lighter day. Calls and
// office visits default to the signed-in sales agent, so her own agenda is the
// right one to show; an in-site visit is a field agent's job (dispatch/board),
// so the planner's own load says nothing useful — hide it there.
const showWorkload = computed(() => !isInSite.value)

// An in-site assignee may only ever be a real field agent (or empty → the
// dispatch pool). When editing, the assignee is pre-filled from the current
// plan, which can be a NON-field user — a call plan owned by the sales agent
// that is now being changed into an in-site visit, or an assignee the current
// user (a non-dispatcher, with the picker hidden) can't touch. Sending that
// stale id would be rejected server-side ("must be an active agent"). We drop
// it to null instead: the server keeps a genuine assignment via inherit and
// routes anything else to the pool for a dispatcher to assign later.
const isFieldAgent = (id) => id != null && id !== '' && props.fieldAgents.some((a) => a.id === id)

// The same/another apartment choice only makes sense while concluding an
// in-site visit (there is a "same" apartment to point back to).
const showApartmentChoice = computed(() => isInSite.value && !!props.currentUnit)
const unitMode = ref('same') // 'same' | 'another'
const anotherPicks = ref([]) // ProjectUnitsPicker v-model (units-only)

const currentUnitLabel = computed(() => unitLine(props.currentUnit))

function update(field, value) {
  emit('update:modelValue', { ...props.modelValue, [field]: value })
}

// Type changes reset the apartment target: leaving in-site drops it; entering
// in-site (with a current apartment) defaults to a second look at this one.
function onType(value) {
  const next = { ...props.modelValue, type: value }
  if (value !== 'in_site_visit') {
    delete next.unit_ids
  } else {
    if (props.currentUnit) {
      unitMode.value = 'same'
      anotherPicks.value = []
      next.unit_ids = [props.currentUnit.id]
    }
    // Entering in-site: an inherited non-field assignee must not ride out.
    if (!isFieldAgent(next.assigned_to)) next.assigned_to = null
  }
  emit('update:modelValue', next)
}

// Push the resolved apartment target out to the parent.
function syncUnitIds() {
  const ids =
    unitMode.value === 'same'
      ? props.currentUnit
        ? [props.currentUnit.id]
        : []
      : anotherPicks.value
          .filter((p) => p.shortlistable_type === 'unit')
          .map((p) => p.shortlistable_id)
  update('unit_ids', ids)
}

function selectMode(mode) {
  unitMode.value = mode
  if (mode === 'same') anotherPicks.value = []
  syncUnitIds()
}

function onAnotherPicks(picks) {
  anotherPicks.value = picks
  syncUnitIds()
}

// Restore the toggle from a draft/edit where a target was already chosen.
onMounted(() => {
  // Editing an already-in-site plan: scrub a stale non-field assignee up front
  // (the picker is hidden for non-dispatchers, so they can't clear it manually).
  if (isInSite.value && !isFieldAgent(props.modelValue.assigned_to)) {
    update('assigned_to', null)
  }
  if (!showApartmentChoice.value) return
  const ids = props.modelValue.unit_ids ?? []
  if (ids.length && !(ids.length === 1 && ids[0] === props.currentUnit.id)) {
    unitMode.value = 'another'
  }
})
</script>

<template>
  <fieldset class="grid gap-3 rounded-xl border border-line p-3 sm:grid-cols-3">
    <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-mute">
      {{ $t('pipeline.nextAction') }}
    </legend>
    <BaseSelect
:label="$t('inventory.type')"
      required
      :model-value="modelValue.type"
      :clearable="false"
      :options="types"
      @change="onType"
    />
    <label class="block">
      <span class="mb-1.5 block text-sm font-medium text-ink"
        >{{ $t('pipeline.dueDate') }}<span class="text-danger" aria-hidden="true"> *</span></span
      >
      <input
        type="date"
        :value="modelValue.due_date"
        :min="today"
        :class="inputClass"
        @input="update('due_date', $event.target.value)"
      />
    </label>
    <div class="block">
      <span class="mb-1.5 block text-sm font-medium text-ink">
        {{ $t('common.time') }}
      </span>
      <TimeField
        :model-value="modelValue.due_time"
        :aria-label="$t('common.time')"
        @update:model-value="update('due_time', $event)"
      />
    </div>

    <!-- Free/busy for the coming week — tap a lighter day to set it as the due
         date. Shown for the agent's own follow-ups (call / office visit). -->
    <div v-if="showWorkload" class="sm:col-span-3">
      <AgentAgendaStrip
        :model-value="modelValue.due_date"
        @update:model-value="(d) => update('due_date', d)"
      />
    </div>

    <!-- In-site: which apartment is this next field visit for? Same one (a
         second look) or another apartment picked from the full property picker. -->
    <div v-if="showApartmentChoice" class="sm:col-span-3">
      <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-mute">{{ $t('pipeline.whichApartment') }}</p>
      <div class="mb-2 flex flex-wrap gap-1.5">
        <button
          type="button"
          class="rounded-full border px-3 py-1.5 text-xs transition-colors"
          :class="
            unitMode === 'same'
              ? 'border-primary bg-highlight font-medium text-ink'
              : 'border-line text-mute hover:border-primary hover:text-ink'
          "
          @click="selectMode('same')"
        >
          <i class="pi pi-home text-[10px]" aria-hidden="true" />
          {{ $t('pipeline.sameApartment') }}<template v-if="currentUnitLabel"> — {{ currentUnitLabel }}</template>
        </button>
        <button
          type="button"
          class="rounded-full border px-3 py-1.5 text-xs transition-colors"
          :class="
            unitMode === 'another'
              ? 'border-primary bg-highlight font-medium text-ink'
              : 'border-line text-mute hover:border-primary hover:text-ink'
          "
          @click="selectMode('another')"
        >
          <i class="pi pi-building text-[10px]" aria-hidden="true" />
          {{ $t('pipeline.anotherApartment') }}
        </button>
      </div>
      <ProjectUnitsPicker
        v-if="unitMode === 'another'"
        :model-value="anotherPicks"
        units-only
        @update:model-value="onAnotherPicks"
      />
    </div>

    <!-- In-site: dispatchers may pick the field agent right away (or leave it
         for the board); everyone else's plan goes to the dispatch pool. -->
    <template v-if="isInSite">
      <BaseSelect
        v-if="canDispatch"
        class="sm:col-span-3"
:label="$t('pipeline.assignToField')"
        :model-value="modelValue.assigned_to"
        :placeholder="$t('pipeline.decideLater')"
        :options="fieldAgents.map((a) => ({ value: a.id, label: a.name }))"
        @change="(v) => update('assigned_to', v)"
      />
      <p v-else class="flex items-center gap-2 text-sm text-mute sm:col-span-3">
        <i class="pi pi-send" aria-hidden="true" />
        {{ $t('pipeline.dispatcherNotified') }}
      </p>
    </template>
  </fieldset>
</template>
