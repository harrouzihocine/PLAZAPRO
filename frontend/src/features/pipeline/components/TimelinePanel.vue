<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import { useDynamicList } from '@/composables/useDynamicList'
import { useClientsStore } from '@/features/clients/clientsStore'
import { pipelineApi } from '@/features/pipeline/api'
import NextActionFields from '@/features/pipeline/components/NextActionFields.vue'
import { useAuthStore } from '@/features/settings/store'

const props = defineProps({ clientId: { type: [String, Number], required: true } })
const store = useClientsStore()
const auth = useAuthStore()
const { items: outcomes } = useDynamicList('visit_outcomes')
const { items: callOutcomes } = useDynamicList('call_outcomes')

const selectClass =
  'w-full rounded-token border border-border bg-bg px-3 py-2 min-h-[44px] text-ink outline-none focus:border-primary'

const canLogCall = () => auth.can('calls.log')
const canScheduleVisit = () => auth.can('visits.assign')
const canConduct = () => auth.can('visits.conduct')

const showCall = ref(false)
const showVisit = ref(false)
const completingId = ref(null)
const units = ref([])

const emptyNextAction = () => ({ type: 'follow_up', due_at: '', assigned_to: '' })
const callForm = reactive({ direction: 'outbound', outcome_id: '', notes: '', next_action: emptyNextAction() })
const visitForm = reactive({ type: 'office', unit_id: '', agent_id: '', scheduled_at: '', notes: '' })
const completeForm = reactive({ outcome_id: '', notes: '', next_action: emptyNextAction() })

// Merge calls + visits into one list, newest first, for the timeline display.
const entries = computed(() => {
  const calls = store.timeline.calls.map((c) => ({ kind: 'call', at: c.called_at, data: c }))
  const visits = store.timeline.visits.map((v) => ({ kind: 'visit', at: v.scheduled_at, data: v }))
  return [...calls, ...visits].sort((a, b) => new Date(b.at) - new Date(a.at))
})

onMounted(() => store.loadTimeline(props.clientId))

async function openVisitForm() {
  showVisit.value = true
  if (!units.value.length) units.value = await pipelineApi.availableUnits()
}

async function submitCall() {
  if (!callForm.next_action.assigned_to || !callForm.next_action.due_at) return
  await store.logCall(props.clientId, {
    direction: callForm.direction,
    outcome_id: callForm.outcome_id || null,
    notes: callForm.notes.trim() || null,
    next_action: callForm.next_action,
  })
  Object.assign(callForm, { direction: 'outbound', outcome_id: '', notes: '', next_action: emptyNextAction() })
  showCall.value = false
}

async function submitVisit() {
  if (!visitForm.agent_id || !visitForm.scheduled_at) return
  if (visitForm.type === 'apartment' && !visitForm.unit_id) return
  await store.scheduleVisit(props.clientId, {
    client_id: props.clientId,
    type: visitForm.type,
    unit_id: visitForm.type === 'apartment' ? visitForm.unit_id : null,
    agent_id: visitForm.agent_id,
    scheduled_at: visitForm.scheduled_at,
    notes: visitForm.notes.trim() || null,
  })
  Object.assign(visitForm, { type: 'office', unit_id: '', agent_id: '', scheduled_at: '', notes: '' })
  showVisit.value = false
}

function openComplete(visit) {
  completingId.value = visit.id
  Object.assign(completeForm, { outcome_id: '', notes: '', next_action: emptyNextAction() })
}

async function submitComplete() {
  if (!completeForm.next_action.assigned_to || !completeForm.next_action.due_at) return
  await store.completeVisit(props.clientId, completingId.value, {
    outcome_id: completeForm.outcome_id || null,
    notes: completeForm.notes.trim() || null,
    next_action: completeForm.next_action,
  })
  completingId.value = null
}
</script>

<template>
  <BaseCard>
    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
      <h2 class="text-sm font-semibold uppercase opacity-60">Timeline</h2>
      <div class="flex gap-1">
        <BaseButton v-if="canLogCall()" variant="ghost" @click="showCall = !showCall">Log call</BaseButton>
        <BaseButton v-if="canScheduleVisit()" variant="ghost" @click="openVisitForm">Schedule visit</BaseButton>
      </div>
    </div>

    <!-- Open next actions -->
    <div v-if="store.timeline.next_actions.length" class="mb-3 space-y-1">
      <div
        v-for="na in store.timeline.next_actions"
        :key="na.id"
        class="flex items-center justify-between rounded-token border border-border px-2 py-1 text-sm"
        :class="na.is_overdue ? 'text-danger' : ''"
      >
        <span>▸ {{ na.type.replace('_', ' ') }} · due {{ new Date(na.due_at).toLocaleDateString() }}</span>
        <span class="opacity-60">{{ na.assigned_to?.name ?? '' }}</span>
      </div>
    </div>

    <!-- Log call form -->
    <form v-if="showCall" class="mb-3 space-y-2 rounded-token bg-surface p-3" @submit.prevent="submitCall">
      <div class="grid gap-2 sm:grid-cols-2">
        <BaseSelect
          v-model="callForm.direction"
          label="Direction"
          :clearable="false"
          :options="[{ value: 'outbound', label: 'outbound' }, { value: 'inbound', label: 'inbound' }]"
        />
        <BaseSelect
          v-model="callForm.outcome_id"
          label="Outcome"
          placeholder="None"
          :options="callOutcomes.map((o) => ({ value: o.id, label: o.label }))"
        />
        <BaseInput v-model="callForm.notes" label="Notes" class="sm:col-span-2" />
      </div>
      <NextActionFields v-model="callForm.next_action" :agents="store.agents" />
      <div class="flex gap-2">
        <BaseButton type="submit" :disabled="store.saving">Save call</BaseButton>
        <BaseButton type="button" variant="ghost" @click="showCall = false">Cancel</BaseButton>
      </div>
    </form>

    <!-- Schedule visit form -->
    <form v-if="showVisit" class="mb-3 space-y-2 rounded-token bg-surface p-3" @submit.prevent="submitVisit">
      <div class="grid gap-2 sm:grid-cols-2">
        <BaseSelect
          v-model="visitForm.type"
          label="Type"
          :clearable="false"
          :options="[{ value: 'office', label: 'office' }, { value: 'apartment', label: 'apartment' }]"
        />
        <BaseSelect
          v-if="visitForm.type === 'apartment'"
          v-model="visitForm.unit_id"
          label="Unit"
          placeholder="Select unit"
          :options="units.map((u) => ({ value: u.id, label: u.reference }))"
        />
        <BaseSelect
          v-model="visitForm.agent_id"
          label="Agent"
          placeholder="Select agent"
          :options="store.agents.map((a) => ({ value: a.id, label: a.name }))"
        />
        <label class="block">
          <span class="mb-1 block text-xs">When</span>
          <input v-model="visitForm.scheduled_at" type="datetime-local" :class="selectClass" />
        </label>
      </div>
      <div class="flex gap-2">
        <BaseButton type="submit" :disabled="store.saving">Schedule</BaseButton>
        <BaseButton type="button" variant="ghost" @click="showVisit = false">Cancel</BaseButton>
      </div>
    </form>

    <!-- Timeline entries -->
    <div class="space-y-2">
      <div v-for="e in entries" :key="e.kind + e.data.id" class="rounded-token border border-border p-2 text-sm">
        <template v-if="e.kind === 'call'">
          <span class="font-medium">📞 Call ({{ e.data.direction }})</span>
          <span class="opacity-60"> · {{ new Date(e.data.called_at).toLocaleString() }}</span>
          <span v-if="e.data.outcome"> · {{ e.data.outcome.label }}</span>
          <span v-if="e.data.agent" class="opacity-60"> · {{ e.data.agent.name }}</span>
          <p v-if="e.data.notes" class="opacity-80">{{ e.data.notes }}</p>
        </template>
        <template v-else>
          <div class="flex items-center justify-between">
            <div>
              <span class="font-medium">🏠 {{ e.data.type }} visit</span>
              <span class="opacity-60"> · {{ new Date(e.data.scheduled_at).toLocaleString() }}</span>
              <span v-if="e.data.unit"> · {{ e.data.unit.reference }}</span>
              <span v-if="e.data.agent" class="opacity-60"> · {{ e.data.agent.name }}</span>
              <span
                class="ml-1 rounded-token px-1.5 py-0.5 text-xs"
                :class="e.data.is_completed ? 'bg-success/15 text-success' : 'bg-warning/15 text-warning'"
              >
                {{ e.data.is_completed ? 'done' : 'scheduled' }}
              </span>
            </div>
            <BaseButton
              v-if="canConduct() && !e.data.is_completed"
              variant="ghost"
              @click="openComplete(e.data)"
            >
              Complete
            </BaseButton>
          </div>

          <!-- Complete visit form (inline) -->
          <form
            v-if="completingId === e.data.id"
            class="mt-2 space-y-2 rounded-token bg-surface p-3"
            @submit.prevent="submitComplete"
          >
            <BaseSelect
              v-model="completeForm.outcome_id"
              label="Outcome"
              placeholder="None"
              :options="outcomes.map((o) => ({ value: o.id, label: o.label }))"
            />
            <NextActionFields v-model="completeForm.next_action" :agents="store.agents" />
            <div class="flex gap-2">
              <BaseButton type="submit" :disabled="store.saving">Complete visit</BaseButton>
              <BaseButton type="button" variant="ghost" @click="completingId = null">Cancel</BaseButton>
            </div>
          </form>
        </template>
      </div>
      <p v-if="!entries.length" class="py-2 text-sm opacity-60">No calls or visits yet.</p>
    </div>
  </BaseCard>
</template>
