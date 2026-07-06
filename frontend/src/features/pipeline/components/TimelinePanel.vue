<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import Button from 'primevue/button'
import Badge from 'primevue/badge'
import Tab from 'primevue/tab'
import TabList from 'primevue/tablist'
import Tabs from 'primevue/tabs'
import BaseModal from '@/components/base/BaseModal.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'
import { useClientsStore } from '@/features/clients/clientsStore'
import AddUnitVisitForm from '@/features/pipeline/components/AddUnitVisitForm.vue'
import CallLogForm from '@/features/pipeline/components/CallLogForm.vue'
import CompleteVisitForm from '@/features/pipeline/components/CompleteVisitForm.vue'
import LogTimeline from '@/features/pipeline/components/LogTimeline.vue'
import NextActionFields from '@/features/pipeline/components/NextActionFields.vue'
import { actionEntries, byNewest, callEntries, visitEntries } from '@/features/pipeline/timeline'
import { useAuthStore } from '@/features/settings/store'
import { useDynamicList } from '@/composables/useDynamicList'
import { toastSuccess } from '@/composables/useConfirm'
import { formatDate, formatTimeIfSet, humanize } from '@/utils/format'

// The interaction timeline — scoped to ONE project when projectId is set (its
// logs + the client-level qualifying calls). The pipeline keeps exactly one
// pending log to fill: the open next action drives the only CTA offered (a
// pending call → "Log the call"; a pending visit → its "Complete" button), so
// the next log created is always the pending one's nature. Forms open in modals.
//
// Logs are split per type in tabs (All keeps the merged story), newest first.
// Every entry expands to its full detail, and edited logs keep their cancelled
// previous versions nested underneath — history is never hidden.
const props = defineProps({
  clientId: { type: [String, Number], required: true },
  projectId: { type: [String, Number], default: null },
  // A frozen (explicitly frozen / archived / cancelled) project is closed to
  // new activity — hide the log / plan / complete affordances (the server
  // also rejects them). Won alone does NOT freeze, and neither do open deals:
  // the client may keep hunting more apartments while one is reserved.
  frozen: { type: Boolean, default: false },
  // The project's conclusion already exists (open or won deal) — completing a
  // visit needs no conclusion of its own (it may still open another deal).
  dealSettled: { type: Boolean, default: false },
})
const emit = defineEmits(['changed'])
const store = useClientsStore()
const auth = useAuthStore()
const { items: changeReasons } = useDynamicList('next_action_change_reasons')

const canLogCall = () => auth.can('calls.log')
// An in-site log is completed by its assigned agent (or a visit admin); office
// visits by any conducting user — mirrors the server rule.
const canComplete = (visit) =>
  auth.can('visits.conduct') &&
  (visit.type !== 'in_site' || visit.agent?.id === auth.user?.id || auth.can('visits.assign'))

// Adding apartment(s) to visit is an in-site (field) action — offered on a
// project story only, to conducting agents, on an open project.
const canAddUnitVisit = computed(() => !!props.projectId && auth.can('visits.conduct'))

const showCall = ref(false)
const showAddUnit = ref(false)
const completing = ref(null) // the visit being completed (modal)

const emptyNextAction = () => ({ type: 'call', due_date: '', due_time: '', assigned_to: '' })

// Edit-with-reason state (corrections = cancel + new version). The reason is
// picked from a list for fast logging, with an optional free note.
const editNa = reactive({ open: false, id: null, reasonId: null, note: '', form: emptyNextAction() })

// Plan-after-the-fact state: a log that didn't need a next action at the time
// can be re-armed later (next actions are optional on the log forms).
const planNa = reactive({ open: false, form: emptyNextAction() })

async function submitPlanNa() {
  if (!nextActionReady(planNa.form)) return
  const payload = { ...planNa.form }
  if (props.projectId) payload.client_project_id = props.projectId
  await store.createNextAction(props.clientId, payload)
  planNa.open = false
  planNa.form = emptyNextAction()
  emit('changed')
}

// In-site plans may stay unassigned — the dispatch board picks the agent.
const nextActionReady = (na) => !!na.due_date

// The single open next action drives what may be logged next.
const pending = computed(() => store.timeline.next_actions[0] ?? null)
const pendingIsCall = computed(() => !pending.value || pending.value.type === 'call')
const openVisits = computed(() =>
  store.timeline.visits.filter((v) => v.status === 'active' && !v.is_completed),
)

// Whether the visit being completed is the last open in-site visit of its
// project — only then does the completion form ask how the thread concludes.
const completingIsLastInSite = computed(() => {
  const v = completing.value
  if (!v || v.type !== 'in_site') return true
  const openInSite = openVisits.value.filter(
    (o) => o.type === 'in_site' && o.client_project_id === v.client_project_id,
  )
  return openInSite.length <= 1
})

// One list per tab — "all" merges the interaction logs (calls + visits);
// planned next actions have their own tab (they are plans, not logs). The
// per-entry rendering (version nesting, expand, detail) lives in LogTimeline.
const tab = ref('all')
const calls = computed(() => callEntries(store.timeline.calls))
const visits = computed(() => visitEntries(store.timeline.visits))
const actions = computed(() => actionEntries(store.timeline.next_action_history ?? []))
const TABS = computed(() => [
  { value: 'all', label: 'All', icon: 'pi pi-history', entries: [...calls.value, ...visits.value].sort(byNewest) },
  { value: 'calls', label: 'Calls', icon: 'pi pi-phone', entries: [...calls.value].sort(byNewest) },
  { value: 'office', label: 'Office visits', icon: 'pi pi-building', entries: visits.value.filter((e) => e.data.type === 'office').sort(byNewest) },
  { value: 'in_site', label: 'In-site visits', icon: 'pi pi-map-marker', entries: visits.value.filter((e) => e.data.type === 'in_site').sort(byNewest) },
  { value: 'actions', label: 'Next actions', icon: 'pi pi-flag', entries: [...actions.value].sort(byNewest) },
])
const entries = computed(() => TABS.value.find((t) => t.value === tab.value)?.entries ?? [])

// Draft identities for the two modal forms; ?resume=<key> (from the drafts
// indicator) reopens the right modal with its draft restored.
const route = useRoute()
const callDraftKey = computed(() => `call-log:${props.clientId}:${props.projectId ?? 'client'}`)
const completeDraftKey = (visit) => `complete-visit:${visit.id}`

onMounted(async () => {
  await store.loadTimeline(props.clientId, props.projectId)

  const resume = route.query.resume
  if (!resume) return
  if (resume === callDraftKey.value) showCall.value = true
  if (String(resume).startsWith('complete-visit:')) {
    const id = Number(String(resume).split(':')[1])
    // Only a LIVE visit may be completed — the payload now includes cancelled
    // (superseded / returned-to-pool) versions, which are history, not work.
    const visit = store.timeline.visits.find(
      (v) => v.id === id && v.status === 'active' && !v.is_completed,
    )
    if (visit) completing.value = visit
  }
})

async function submitCall(payload) {
  if (props.projectId) payload.client_project_id = props.projectId
  await store.logCall(props.clientId, payload)
  showCall.value = false
  // A call can conclude into THE deal — surface it in the deal panel right away.
  if (payload.closure?.type === 'deal' && props.projectId) {
    await store.loadDeals(props.projectId)
  }
  emit('changed')
}

// The visit self-closes in one call: its conclusion (next action, or a
// desire/archive/deal closure) rides inside the completion payload — a deal
// closure opens THE deal on the visit's project with provenance, server-side.
async function submitComplete(payload) {
  const visit = completing.value
  await store.completeVisit(props.clientId, visit.id, payload)
  completing.value = null
  if (payload.closure?.type === 'deal' && visit.client_project_id) {
    await store.loadDeals(visit.client_project_id)
  }
  emit('changed')
}

// Add apartment(s) to visit, standalone — no open visit to complete first.
async function submitAddUnit(payload) {
  await store.proposeInSiteVisit(props.clientId, props.projectId, payload)
  showAddUnit.value = false
  toastSuccess(
    payload.assigned_to
      ? 'Added to the field agent’s visits.'
      : 'Added to the dispatch pool — the dispatcher was notified.',
  )
  emit('changed')
}

// --- Edit the open next action (change type/when/assignee) with a reason ---
function openEditNa(na) {
  const d = na.due_at ? new Date(na.due_at) : null
  editNa.open = true
  editNa.id = na.id
  editNa.reasonId = null
  editNa.note = ''
  Object.assign(editNa.form, {
    type: na.type,
    due_date: d ? d.toISOString().slice(0, 10) : '',
    due_time: '',
    assigned_to: na.assigned_to?.id ?? '',
  })
}

async function submitEditNa() {
  if (!editNa.reasonId || !nextActionReady(editNa.form)) return
  await store.correctNextAction(props.clientId, editNa.id, {
    reason_id: editNa.reasonId,
    note: editNa.note.trim() || null,
    ...editNa.form,
  })
  editNa.open = false
  emit('changed')
}
</script>

<template>
  <div>
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
      <h3 class="flex items-center gap-2 text-sm font-semibold text-ink">
        <i class="pi pi-history text-mute" aria-hidden="true" />
        Timeline
      </h3>
      <!-- One pending log at a time: the call CTA shows only when the open next
           action IS a call (or nothing is planned yet). -->
      <span class="flex items-center gap-2">
        <Button
          v-if="canLogCall() && !pending && !frozen"
          label="Plan next action"
          icon="pi pi-flag"
          size="small"
          severity="secondary"
          outlined
          @click="planNa.open = !planNa.open"
        />
        <!-- Add apartment(s) to visit anytime — no need to complete an open
             in-site visit first (the field agent finds more units on site). -->
        <Button
          v-if="canAddUnitVisit && !frozen"
          label="Add unit to visit"
          icon="pi pi-map-marker"
          size="small"
          severity="secondary"
          outlined
          @click="showAddUnit = true"
        />
        <Button
          v-if="canLogCall() && pendingIsCall && !frozen"
          label="Log call"
          icon="pi pi-phone"
          size="small"
          @click="showCall = true"
        />
      </span>
    </div>

    <!-- Plan a next action after the fact (nothing is pending right now). -->
    <form
      v-if="planNa.open && !pending"
      class="mb-4 space-y-3 rounded-xl border border-line bg-card p-3"
      @submit.prevent="submitPlanNa"
    >
      <NextActionFields v-model="planNa.form" :field-agents="store.agents" />
      <div class="flex gap-2">
        <Button
          type="submit"
          label="Plan it"
          size="small"
          :disabled="store.saving || !nextActionReady(planNa.form)"
        />
        <Button
          type="button"
          label="Cancel"
          size="small"
          severity="secondary"
          outlined
          @click="planNa.open = false"
        />
      </div>
    </form>

    <!-- The open next action (the one pending log to fill), editable with a reason. -->
    <div v-if="store.timeline.next_actions.length" class="mb-4 space-y-2">
      <div
        v-for="na in store.timeline.next_actions"
        :key="na.id"
        class="rounded-lg border px-3 py-2.5 text-sm"
        :class="
          na.is_overdue
            ? 'border-red-300 bg-red-50 dark:border-red-500/40 dark:bg-red-500/10'
            : 'border-primary-200 bg-primary-50 dark:border-primary-500/30 dark:bg-primary-500/10'
        "
      >
        <div class="flex items-center justify-between gap-2">
          <span class="flex items-center gap-2">
            <i
              :class="
                na.is_overdue
                  ? 'pi pi-exclamation-circle text-danger'
                  : 'pi pi-flag text-primary-600 dark:text-primary-400'
              "
              aria-hidden="true"
            />
            <span>
              Next:
              <span class="font-semibold">{{ humanize(na.type) }}</span>
              <span :class="na.is_overdue ? 'font-medium text-danger' : 'text-mute'">
                · due {{ formatDate(na.due_at)
                }}<template v-if="formatTimeIfSet(na.due_at)">, {{ formatTimeIfSet(na.due_at) }}</template>
              </span>
            </span>
          </span>
          <span class="flex items-center gap-1.5">
            <span v-if="na.assigned_to?.name" class="text-xs text-mute">
              {{ na.assigned_to.name }}
            </span>
            <Button
              v-if="canLogCall()"
              icon="pi pi-pencil"
              text
              rounded
              size="small"
              severity="secondary"
              aria-label="Edit next action (with a reason)"
              @click="openEditNa(na)"
            />
          </span>
        </div>

        <!-- Edit-with-reason form: switch a call into a visit, change when/who, etc. -->
        <form
          v-if="editNa.open && editNa.id === na.id"
          class="mt-3 space-y-3 rounded-lg bg-card p-3 shadow-card"
          @submit.prevent="submitEditNa"
        >
          <NextActionFields v-model="editNa.form" :field-agents="store.agents" />
          <BaseSelect
            v-model="editNa.reasonId"
            label="Reason for the change"
            required
            placeholder="Pick a reason"
            :options="changeReasons.map((r) => ({ value: r.id, label: r.label }))"
          />
          <BaseTextarea v-model="editNa.note" label="Note" :rows="2" />
          <div class="flex gap-2">
            <Button
              type="submit"
              label="Save change"
              size="small"
              :disabled="store.saving || !editNa.reasonId"
            />
            <Button
              type="button"
              label="Cancel"
              size="small"
              severity="secondary"
              outlined
              @click="editNa.open = false"
            />
          </div>
        </form>
      </div>

      <!-- A pending visit's log is completed on the visit itself below. -->
      <p v-if="!pendingIsCall && openVisits.length" class="px-1 text-xs text-mute">
        The pending log is the scheduled visit — fill it with its “Complete” button below.
      </p>
    </div>

    <!-- One tab per log type; "All" keeps the merged story. -->
    <Tabs v-model:value="tab" scrollable :pt="{ root: { class: 'mb-1' } }">
      <TabList>
        <Tab v-for="t in TABS" :key="t.value" :value="t.value" class="!py-2.5">
          <span class="flex items-center gap-1.5 text-sm">
            <i :class="t.icon" aria-hidden="true" />
            {{ t.label }}
            <Badge v-if="t.entries.length" :value="t.entries.length" severity="secondary" size="small" />
          </span>
        </Tab>
      </TabList>
    </Tabs>

    <!-- Timeline entries (newest first, expandable, cancelled versions kept).
         LogTimeline owns the per-entry design; the visit "Complete" affordance
         is injected through its #actions slot. -->
    <LogTimeline
      :entries="entries"
      :empty-title="tab === 'all' ? 'No calls or visits yet' : 'Nothing here yet'"
      empty-body="The story starts with the first logged call."
    >
      <template #actions="{ entry: e }">
        <Button
          v-if="e.kind === 'visit' && e.data.status !== 'cancelled' && canComplete(e.data) && !e.data.is_completed && !frozen"
          label="Complete"
          icon="pi pi-check"
          size="small"
          outlined
          @click="completing = e.data"
        />
      </template>
    </LogTimeline>

    <!-- Log call — the fast-entry qualification form (properties / desire). -->
    <BaseModal v-if="showCall" title="Log a call" @close="showCall = false">
      <CallLogForm
        :client="store.current"
        :project-id="projectId"
        :field-agents="store.agents"
        :saving="store.saving"
        :desire="store.desire"
        :draft-key="callDraftKey"
        @submit="submitCall"
        @cancel="showCall = false"
      />
    </BaseModal>

    <!-- Add apartment(s) to visit, standalone — picks + date go out as in-site
         visit(s) (assigned by a dispatcher, else pooled for the board). -->
    <BaseModal v-if="showAddUnit" title="Add unit to visit" @close="showAddUnit = false">
      <AddUnitVisitForm
        :field-agents="store.agents"
        :saving="store.saving"
        @submit="submitAddUnit"
        @cancel="showAddUnit = false"
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
        :can-manage-shortlist="auth.can('shortlist.manage')"
        :is-last-in-site="completingIsLastInSite"
        :deal-settled="dealSettled"
        :draft-key="completeDraftKey(completing)"
        @submit="submitComplete"
        @cancel="completing = null"
      />
    </BaseModal>
  </div>
</template>
