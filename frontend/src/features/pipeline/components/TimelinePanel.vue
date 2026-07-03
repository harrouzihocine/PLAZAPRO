<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseModal from '@/components/base/BaseModal.vue'
import { useClientsStore } from '@/features/clients/clientsStore'
import CallLogForm from '@/features/pipeline/components/CallLogForm.vue'
import CompleteVisitForm from '@/features/pipeline/components/CompleteVisitForm.vue'
import NextActionFields from '@/features/pipeline/components/NextActionFields.vue'
import { useAuthStore } from '@/features/settings/store'

// The interaction timeline — scoped to ONE project when projectId is set (its
// logs + the client-level qualifying calls). The pipeline keeps exactly one
// pending log to fill: the open next action drives the only CTA offered (a
// pending call → "Log the call"; a pending visit → its "Complete" button), so
// the next log created is always the pending one's nature. Forms open in modals.
const props = defineProps({
  clientId: { type: [String, Number], required: true },
  projectId: { type: [String, Number], default: null },
})
const emit = defineEmits(['changed'])
const store = useClientsStore()
const auth = useAuthStore()

const canLogCall = () => auth.can('calls.log')
// An in-site log is completed by its assigned agent (or a visit admin); office
// visits by any conducting user — mirrors the server rule.
const canComplete = (visit) =>
  auth.can('visits.conduct') &&
  (visit.type !== 'in_site' || visit.agent?.id === auth.user?.id || auth.can('visits.assign'))

const showCall = ref(false)
const completing = ref(null) // the visit being completed (modal)

const emptyNextAction = () => ({ type: 'call', due_date: '', due_time: '', assigned_to: '' })

// Edit-with-reason state (corrections = cancel + new version).
const editNa = reactive({ open: false, id: null, reason: '', form: emptyNextAction() })

const nextActionReady = (na) => !!na.due_date && (na.type !== 'in_site_visit' || !!na.assigned_to)

// The single open next action drives what may be logged next.
const pending = computed(() => store.timeline.next_actions[0] ?? null)
const pendingIsCall = computed(() => !pending.value || pending.value.type === 'call')
const openVisits = computed(() => store.timeline.visits.filter((v) => !v.is_completed))

// Merge calls + visits into one list, newest first, for the timeline display.
const entries = computed(() => {
  const calls = store.timeline.calls.map((c) => ({ kind: 'call', at: c.called_at, data: c }))
  const visits = store.timeline.visits.map((v) => ({ kind: 'visit', at: v.scheduled_at, data: v }))
  return [...calls, ...visits].sort((a, b) => new Date(b.at) - new Date(a.at))
})

onMounted(async () => {
  await store.loadTimeline(props.clientId, props.projectId)
  // A call is the first thing logged on a new client — open the form when the
  // timeline has no calls yet and the user can log one.
  if (canLogCall() && !store.timeline.calls.length) showCall.value = true
})

async function submitCall(payload) {
  if (props.projectId) payload.client_project_id = props.projectId
  await store.logCall(props.clientId, payload)
  showCall.value = false
  emit('changed')
}

// Completing may also carry the deal step ("client decided") — the visit is
// completed first, then THE deal opens on the visit's project with provenance.
async function submitComplete(payload) {
  const { deal, ...completion } = payload
  const visit = completing.value
  await store.completeVisit(props.clientId, visit.id, completion)
  completing.value = null
  if (deal && visit.client_project_id) {
    await store.createDeal(props.clientId, visit.client_project_id, deal)
    await store.loadDeals(visit.client_project_id)
  }
  emit('changed')
}

// --- Edit the open next action (change type/when/assignee) with a reason ---
function openEditNa(na) {
  const d = na.due_at ? new Date(na.due_at) : null
  editNa.open = true
  editNa.id = na.id
  editNa.reason = ''
  Object.assign(editNa.form, {
    type: na.type,
    due_date: d ? d.toISOString().slice(0, 10) : '',
    due_time: '',
    assigned_to: na.assigned_to?.id ?? '',
  })
}

async function submitEditNa() {
  if (!editNa.reason.trim() || !nextActionReady(editNa.form)) return
  await store.correctNextAction(props.clientId, editNa.id, { reason: editNa.reason.trim(), ...editNa.form })
  editNa.open = false
  emit('changed')
}
</script>

<template>
  <div>
    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
      <h3 class="text-sm font-semibold uppercase opacity-60">Timeline</h3>
      <!-- One pending log at a time: the call CTA shows only when the open next
           action IS a call (or nothing is planned yet). -->
      <BaseButton v-if="canLogCall() && pendingIsCall" variant="ghost" @click="showCall = true">
        📞 Log call
      </BaseButton>
    </div>

    <!-- The open next action (the one pending log to fill), editable with a reason. -->
    <div v-if="store.timeline.next_actions.length" class="mb-3 space-y-1">
      <div
        v-for="na in store.timeline.next_actions"
        :key="na.id"
        class="rounded-token border border-primary/40 bg-primary/5 px-2 py-1.5 text-sm"
        :class="na.is_overdue ? 'text-danger' : ''"
      >
        <div class="flex items-center justify-between gap-2">
          <span>▸ Next: <span class="font-medium">{{ na.type.replace(/_/g, ' ') }}</span> · due {{ new Date(na.due_at).toLocaleDateString() }}</span>
          <span class="flex items-center gap-2">
            <span class="opacity-60">{{ na.assigned_to?.name ?? '' }}</span>
            <button
              v-if="canLogCall()"
              type="button"
              class="opacity-60 hover:opacity-100"
              title="Edit next action (with a reason)"
              @click="openEditNa(na)"
            >
              ✎
            </button>
          </span>
        </div>

        <!-- Edit-with-reason form: switch a call into a visit, change when/who, etc. -->
        <form
          v-if="editNa.open && editNa.id === na.id"
          class="mt-2 space-y-2 rounded-token bg-surface p-2"
          @submit.prevent="submitEditNa"
        >
          <NextActionFields v-model="editNa.form" :field-agents="store.agents" />
          <BaseInput v-model="editNa.reason" label="Reason for the change" />
          <div class="flex gap-2">
            <BaseButton type="submit" :disabled="store.saving || !editNa.reason.trim()">Save change</BaseButton>
            <BaseButton type="button" variant="ghost" @click="editNa.open = false">Cancel</BaseButton>
          </div>
        </form>
      </div>

      <!-- A pending visit's log is completed on the visit itself below. -->
      <p v-if="!pendingIsCall && openVisits.length" class="px-1 text-xs opacity-60">
        The pending log is the scheduled visit — fill it with its “Complete” button below.
      </p>
    </div>

    <!-- Timeline entries -->
    <div class="space-y-2">
      <div v-for="e in entries" :key="e.kind + e.data.id" class="rounded-token border border-border p-2 text-sm">
        <template v-if="e.kind === 'call'">
          <span class="font-medium">📞 Call ({{ e.data.direction }})</span>
          <span class="opacity-60"> · {{ new Date(e.data.called_at).toLocaleString() }}</span>
          <span v-if="e.data.outcome"> · {{ e.data.outcome.label }}</span>
          <span v-if="e.data.agent" class="opacity-60"> · {{ e.data.agent.name }}</span>
          <p v-if="e.data.notes" class="whitespace-pre-line opacity-80">{{ e.data.notes }}</p>
          <p v-if="e.data.edited" class="text-xs italic opacity-60">
            ✎ edited{{ e.data.edit_reason ? ' — ' + e.data.edit_reason : '' }}
          </p>
        </template>
        <template v-else>
          <div class="flex items-center justify-between">
            <div>
              <span class="font-medium">🏠 {{ e.data.type.replace('_', '-') }} visit</span>
              <span class="opacity-60"> · {{ new Date(e.data.scheduled_at).toLocaleString() }}</span>
              <span v-if="e.data.unit"> · {{ e.data.unit.reference }}<template v-if="e.data.unit.property_type"> ({{ e.data.unit.property_type }})</template></span>
              <span v-if="e.data.agent" class="opacity-60"> · {{ e.data.agent.name }}</span>
              <span
                class="ml-1 rounded-token px-1.5 py-0.5 text-xs"
                :class="e.data.is_completed ? 'bg-success/15 text-success' : 'bg-warning/15 text-warning'"
              >
                {{ e.data.is_completed ? 'done' : 'scheduled' }}
              </span>
              <span v-if="e.data.outcome" class="ml-1 text-xs opacity-70">{{ e.data.outcome.label }}</span>
              <span v-if="e.data.edited" class="ml-1 text-xs italic opacity-60" :title="e.data.edit_reason">✎ edited</span>
            </div>
            <BaseButton
              v-if="canComplete(e.data) && !e.data.is_completed"
              variant="ghost"
              @click="completing = e.data"
            >
              Complete
            </BaseButton>
          </div>
          <p v-if="e.data.notes" class="whitespace-pre-line opacity-80">{{ e.data.notes }}</p>
        </template>
      </div>
      <p v-if="!entries.length" class="py-2 text-sm opacity-60">No calls or visits yet.</p>
    </div>

    <!-- Log call — the fast-entry qualification form (properties / desire). -->
    <BaseModal v-if="showCall" title="Log a call" @close="showCall = false">
      <CallLogForm
        :client="store.current"
        :field-agents="store.agents"
        :saving="store.saving"
        :desire="store.desire"
        @submit="submitCall"
        @cancel="showCall = false"
      />
    </BaseModal>

    <!-- Complete visit (rapid log; office variant embeds the shortlist + deal step). -->
    <BaseModal
      v-if="completing"
      :title="`Complete ${completing.type.replace('_', '-')} visit`"
      @close="completing = null"
    >
      <CompleteVisitForm
        :visit="completing"
        :field-agents="store.agents"
        :saving="store.saving"
        :can-deal="auth.can('visits.conduct')"
        @submit="submitComplete"
        @cancel="completing = null"
      />
    </BaseModal>
  </div>
</template>
