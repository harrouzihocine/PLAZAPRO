<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
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
import { pipelineApi } from '@/features/pipeline/api'
import { actionEntries, byNewest, callEntries, visitEntries } from '@/features/pipeline/timeline'
import { useAuthStore } from '@/features/settings/store'
import { useDynamicList, itemLabel } from '@/composables/useDynamicList'
import { toastInfo, toastSuccess } from '@/composables/useConfirm'
import { dateInputValue, formatDate, formatTimeIfSet, humanize, timeInputValue } from '@/utils/format'
import { i18n, t } from '@/i18n'

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
  // the client may keep hunting more apartments while a deal is open.
  frozen: { type: Boolean, default: false },
  // The project's conclusion already exists (open or won deal) — completing a
  // visit needs no conclusion of its own (it may still open another deal).
  dealSettled: { type: Boolean, default: false },
  // The signed-in user is only DISPATCHED here (a field agent holding the
  // project's in-site visit, not a contributor / the client's own agent):
  // their remit is that visit — hide the project's other log affordances
  // (log call / plan / complete office visit), which the server rejects
  // anyway. Set from the project's is_dispatch_only flag.
  dispatchOnly: { type: Boolean, default: false },
})
const emit = defineEmits(['changed'])
const store = useClientsStore()
const auth = useAuthStore()
const { items: changeReasons } = useDynamicList('next_action_change_reasons')

const canLogCall = () => auth.can('calls.log') && !props.dispatchOnly
// Planning a standalone next action has its own grant (split from calls.log)
// so the button can be handed out person-by-person — mirrors the server rule.
const canPlanNextAction = computed(() => auth.can('next_actions.plan') && !props.dispatchOnly)
// An in-site log is completed by its assigned agent (or a visit admin); office
// visits by any conducting user EXCEPT one only dispatched to the project —
// mirrors the server rules (CompleteVisitRequest).
const canComplete = (visit) =>
  auth.can('visits.conduct') &&
  (visit.type !== 'in_site' || visit.agent?.id === auth.user?.id || auth.can('visits.assign')) &&
  (visit.type !== 'office' || !props.dispatchOnly)

// Adding apartment(s) to visit is offered on a project story only, on an open
// project, under its own grant (split from visits.conduct) — hand it out
// person-by-person like the server does.
const canAddUnitVisit = computed(() => !!props.projectId && auth.can('visits.propose'))

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

// Localized label for a next-action type ('call' | 'office_visit' | 'in_site_visit').
const naTypeLabel = (type) =>
  type === 'call' ? t('pipeline.typeCall') : i18n.global.te(`status.${type}`) ? t(`status.${type}`) : humanize(type)

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
  { value: 'all', label: t('common.all'), icon: 'pi pi-history', entries: [...calls.value, ...visits.value].sort(byNewest) },
  { value: 'calls', label: t('pipeline.tabCalls'), icon: 'pi pi-phone', entries: [...calls.value].sort(byNewest) },
  { value: 'office', label: t('pipeline.tabOfficeVisits'), icon: 'pi pi-building', entries: visits.value.filter((e) => e.data.type === 'office').sort(byNewest) },
  { value: 'in_site', label: t('pipeline.tabInSiteVisits'), icon: 'pi pi-map-marker', entries: visits.value.filter((e) => e.data.type === 'in_site').sort(byNewest) },
  { value: 'actions', label: t('pipeline.tabNextActions'), icon: 'pi pi-flag', entries: [...actions.value].sort(byNewest) },
])
const entries = computed(() => TABS.value.find((t) => t.value === tab.value)?.entries ?? [])

// Draft identities for the two modal forms; ?resume=<key> (from the drafts
// indicator) reopens the right modal with its draft restored.
const route = useRoute()
const router = useRouter()
const callDraftKey = computed(() => `call-log:${props.clientId}:${props.projectId ?? 'client'}`)
const completeDraftKey = (visit) => `complete-visit:${visit.id}`

// Click-to-call (?logcall=<id>): the switch-then-log handshake — when the plan
// on file is not a call, the reason form comes first and the call modal opens
// right after it is saved.
const logcallSwitch = ref(false)

// ?logcall=<id> (the click-to-call reminder): open the log-call modal. The
// pipeline's one-pending-log rule stands — a planned visit is not silently
// bulldozed; the agent first switches the plan to a call, with a reason.
function handleLogcall() {
  if (!route.query.logcall || !canLogCall() || props.frozen) return
  // Guard against a route transition away from this panel's own page: the
  // global route may already carry another client's params while we unmount.
  if (String(route.params.id ?? props.clientId) !== String(props.clientId)) return

  if (pendingIsCall.value) {
    showCall.value = true
  } else {
    // Order matters: openEditNa resets the handshake flag (a manual pencil
    // edit must never inherit it), so arm it after.
    openEditNa(pending.value)
    editNa.form.type = 'call'
    logcallSwitch.value = true
    toastInfo(t('pipeline.switchToCallNotice', { type: naTypeLabel(pending.value.type) }))
  }
}

// The prompt's "Log the call" may land on a page that is ALREADY open (the
// agent was reading this project on the PC while dialing) — only the query
// changes then, no remount, so onMounted alone would miss it.
watch(
  () => route.query.logcall,
  (v) => {
    if (v) handleLogcall()
  },
)

onMounted(async () => {
  await store.loadTimeline(props.clientId, props.projectId)

  handleLogcall()

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
  // The click-to-call reminder that led here is answered — best-effort close
  // (other open sessions drop their prompt too), and the param is spent.
  if (route.query.logcall) {
    pipelineApi.closeCallRequest(route.query.logcall, 'logged').catch(() => {})
    router.replace({ query: { ...route.query, logcall: undefined } })
  }
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
  toastSuccess(payload.assigned_to ? t('pipeline.addedToAgentVisits') : t('pipeline.addedToPool'))
  emit('changed')
}

// --- Edit the open next action (change type/when/assignee) with a reason ---
function openEditNa(na) {
  logcallSwitch.value = false // a manual edit is not the click-to-call handshake
  editNa.open = true
  editNa.id = na.id
  editNa.reasonId = null
  editNa.note = ''
  Object.assign(editNa.form, {
    type: na.type,
    due_date: dateInputValue(na.due_at),
    due_time: timeInputValue(na.due_at),
    assigned_to: na.assigned_to?.id ?? '',
  })
}

// Declining the switch (Cancel) ends the handshake — without this the flag
// stays latched and a LATER unrelated correction would pop the call modal.
function closeEditNa() {
  editNa.open = false
  logcallSwitch.value = false
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
  // Click-to-call handshake: the plan is now a call — straight into logging it.
  if (logcallSwitch.value) {
    logcallSwitch.value = false
    showCall.value = true
  }
}
</script>

<template>
  <div>
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
      <h3 class="flex items-center gap-2 text-sm font-semibold text-ink">
        <i class="pi pi-history text-mute" aria-hidden="true" />
        {{ $t('pipeline.timeline') }}
      </h3>
      <!-- One pending log at a time: the call CTA shows only when the open next
           action IS a call (or nothing is planned yet). -->
      <span class="flex flex-wrap items-center justify-end gap-2">
        <Button
          v-if="canPlanNextAction && !pending && !frozen"
:label="$t('calls.planNextAction')"
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
:label="$t('pipeline.addUnitToVisit')"
          icon="pi pi-map-marker"
          size="small"
          severity="secondary"
          outlined
          @click="showAddUnit = true"
        />
        <Button
          v-if="canLogCall() && pendingIsCall && !frozen"
:label="$t('pipeline.logCall')"
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
:label="$t('pipeline.planIt')"
          size="small"
          :disabled="store.saving || !nextActionReady(planNa.form)"
        />
        <Button
          type="button"
:label="$t('common.cancel')"
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
              {{ $t('pipeline.next') }}
              <span class="font-semibold">{{ naTypeLabel(na.type) }}</span>
              <span :class="na.is_overdue ? 'font-medium text-danger' : 'text-mute'">
                · {{ $t('pipeline.due', { date: formatDate(na.due_at) })
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
:aria-label="$t('pipeline.editNaAria')"
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
:label="$t('pipeline.changeReason')"
            required
            :placeholder="$t('pipeline.pickReason')"
            :options="changeReasons.map((r) => ({ value: r.id, label: itemLabel(r) }))"
          />
          <BaseTextarea v-model="editNa.note" :label="$t('common.note')" :rows="2" />
          <div class="flex gap-2">
            <Button
              type="submit"
:label="$t('pipeline.saveChange')"
              size="small"
              :disabled="store.saving || !editNa.reasonId"
            />
            <Button
              type="button"
:label="$t('common.cancel')"
              size="small"
              severity="secondary"
              outlined
              @click="closeEditNa"
            />
          </div>
        </form>
      </div>

      <!-- A pending visit's log is completed on the visit itself below. -->
      <p v-if="!pendingIsCall && openVisits.length" class="px-1 text-xs text-mute">
        {{ $t('pipeline.pendingVisitNotice') }}
      </p>
    </div>

    <!-- One tab per log type; "All" keeps the merged story. -->
    <Tabs v-model:value="tab" scrollable :pt="{ root: { class: 'mb-1' } }">
      <TabList>
        <Tab v-for="tabDef in TABS" :key="tabDef.value" :value="tabDef.value" class="!py-2.5">
          <span class="flex items-center gap-1.5 text-sm">
            <i :class="tabDef.icon" aria-hidden="true" />
            {{ tabDef.label }}
            <Badge v-if="tabDef.entries.length" :value="tabDef.entries.length" severity="secondary" size="small" />
          </span>
        </Tab>
      </TabList>
    </Tabs>

    <!-- Timeline entries (newest first, expandable, cancelled versions kept).
         LogTimeline owns the per-entry design; the visit "Complete" affordance
         is injected through its #actions slot. -->
    <LogTimeline
      :entries="entries"
      :empty-title="tab === 'all' ? $t('pipeline.emptyAll') : $t('pipeline.emptyTab')"
      :empty-body="$t('pipeline.emptyBody')"
    >
      <template #actions="{ entry: e }">
        <Button
          v-if="e.kind === 'visit' && e.data.status !== 'cancelled' && canComplete(e.data) && !e.data.is_completed && !frozen"
:label="$t('pipeline.complete')"
          icon="pi pi-check"
          size="small"
          outlined
          @click="completing = e.data"
        />
      </template>
    </LogTimeline>

    <!-- Log call — the fast-entry qualification form (properties / desire). -->
    <BaseModal v-if="showCall" :title="$t('pipeline.logACall')" @close="showCall = false">
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
    <BaseModal v-if="showAddUnit" :title="$t('pipeline.addUnitToVisit')" @close="showAddUnit = false">
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
      :title="completing.type === 'in_site' ? $t('visits.completeInSiteLabel') : $t('visits.completeOfficeLabel')"
      @close="completing = null"
    >
      <CompleteVisitForm
        :visit="completing"
        :field-agents="store.agents"
        :saving="store.saving"
        :can-deal="auth.can('visits.conduct')"
        :is-last-in-site="completingIsLastInSite"
        :deal-settled="dealSettled"
        :draft-key="completeDraftKey(completing)"
        @submit="submitComplete"
        @cancel="completing = null"
      />
    </BaseModal>
  </div>
</template>
