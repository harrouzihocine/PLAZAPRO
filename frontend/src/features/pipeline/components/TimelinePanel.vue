<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Button from 'primevue/button'
import Badge from 'primevue/badge'
import Tab from 'primevue/tab'
import TabList from 'primevue/tablist'
import Tabs from 'primevue/tabs'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseModal from '@/components/base/BaseModal.vue'
import StatusTag from '@/components/ui/StatusTag.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useClientsStore } from '@/features/clients/clientsStore'
import CallLogForm from '@/features/pipeline/components/CallLogForm.vue'
import CompleteVisitForm from '@/features/pipeline/components/CompleteVisitForm.vue'
import NextActionFields from '@/features/pipeline/components/NextActionFields.vue'
import { useAuthStore } from '@/features/settings/store'
import { useDynamicList } from '@/composables/useDynamicList'
import { formatDate, formatDateTime, humanize } from '@/utils/format'

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
})
const emit = defineEmits(['changed'])
const store = useClientsStore()
const auth = useAuthStore()
const { items: officeChecklist } = useDynamicList('office_visit_checklist')

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

// --- Version chains: a superseded row nests under its replacement ---------
// Rows arrive flat (active + cancelled). The top level keeps rows nobody
// replaced; each carries its older versions (walked via supersedes_id).
function withVersions(list) {
  const byId = new Map(list.map((x) => [x.id, x]))
  const replacedIds = new Set(list.map((x) => x.supersedes_id).filter((id) => id != null))
  return list
    .filter((x) => !replacedIds.has(x.id))
    .map((x) => {
      const versions = []
      let cur = x
      while (cur?.supersedes_id != null && byId.has(cur.supersedes_id)) {
        cur = byId.get(cur.supersedes_id)
        versions.push(cur)
      }
      return { ...x, previous_versions: versions }
    })
}

const callEntries = computed(() =>
  withVersions(store.timeline.calls).map((c) => ({ kind: 'call', at: c.called_at, data: c })),
)
const visitEntries = computed(() =>
  withVersions(store.timeline.visits).map((v) => ({ kind: 'visit', at: v.scheduled_at, data: v })),
)
const actionEntries = computed(() =>
  withVersions(store.timeline.next_action_history ?? []).map((a) => ({
    kind: 'action',
    at: a.due_at,
    data: a,
  })),
)

const byNewest = (a, b) => new Date(b.at) - new Date(a.at)

// One list per tab — "all" merges the interaction logs (calls + visits);
// planned next actions have their own tab (they are plans, not logs).
const tab = ref('all')
const TABS = computed(() => [
  { value: 'all', label: 'All', icon: 'pi pi-history', entries: [...callEntries.value, ...visitEntries.value].sort(byNewest) },
  { value: 'calls', label: 'Calls', icon: 'pi pi-phone', entries: [...callEntries.value].sort(byNewest) },
  { value: 'office', label: 'Office visits', icon: 'pi pi-building', entries: visitEntries.value.filter((e) => e.data.type === 'office').sort(byNewest) },
  { value: 'in_site', label: 'In-site visits', icon: 'pi pi-map-marker', entries: visitEntries.value.filter((e) => e.data.type === 'in_site').sort(byNewest) },
  { value: 'actions', label: 'Next actions', icon: 'pi pi-flag', entries: [...actionEntries.value].sort(byNewest) },
])
const entries = computed(() => TABS.value.find((t) => t.value === tab.value)?.entries ?? [])

// --- Expandable detail rows ------------------------------------------------
const expanded = ref(new Set())
const keyOf = (e) => `${e.kind}-${e.data.id}`
function toggle(e) {
  const k = keyOf(e)
  const next = new Set(expanded.value)
  next.has(k) ? next.delete(k) : next.add(k)
  expanded.value = next
}
const isExpanded = (e) => expanded.value.has(keyOf(e))
const isCancelled = (row) => row.status === 'cancelled'

const checklistLabels = (ids) =>
  (ids ?? []).map((id) => officeChecklist.value.find((c) => c.id === id)?.label ?? `#${id}`)

const mapsUrl = (visit) =>
  visit.unit?.location?.latitude != null && visit.unit?.location?.longitude != null
    ? `https://www.google.com/maps/search/?api=1&query=${visit.unit.location.latitude},${visit.unit.location.longitude}`
    : null

onMounted(() => store.loadTimeline(props.clientId, props.projectId))

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
  await store.correctNextAction(props.clientId, editNa.id, {
    reason: editNa.reason.trim(),
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
          v-if="canLogCall() && !pending"
          label="Plan next action"
          icon="pi pi-flag"
          size="small"
          severity="secondary"
          outlined
          @click="planNa.open = !planNa.open"
        />
        <Button
          v-if="canLogCall() && pendingIsCall"
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
                · due {{ formatDate(na.due_at) }}
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
          <BaseInput v-model="editNa.reason" label="Reason for the change" />
          <div class="flex gap-2">
            <Button
              type="submit"
              label="Save change"
              size="small"
              :disabled="store.saving || !editNa.reason.trim()"
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

    <!-- Timeline entries (newest first, expandable, cancelled versions kept) -->
    <EmptyState
      v-if="!entries.length"
      icon="pi pi-phone"
      :title="tab === 'all' ? 'No calls or visits yet' : 'Nothing here yet'"
      body="The story starts with the first logged call."
    />
    <ol v-else class="mt-3 space-y-0">
      <li
        v-for="(e, i) in entries"
        :key="keyOf(e)"
        class="relative flex gap-3 pb-5 last:pb-0"
      >
        <span
          v-if="i < entries.length - 1"
          class="absolute left-4 top-9 h-[calc(100%-2rem)] w-px -translate-x-1/2 bg-line"
          aria-hidden="true"
        />
        <span
          class="z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs"
          :class="
            isCancelled(e.data)
              ? 'bg-surface-100 text-mute dark:bg-surface-800'
              : e.kind === 'call'
                ? 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300'
                : e.kind === 'action'
                  ? 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300'
                  : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'
          "
        >
          <i
            :class="
              e.kind === 'call'
                ? 'pi pi-phone'
                : e.kind === 'action'
                  ? 'pi pi-flag'
                  : e.data.type === 'in_site'
                    ? 'pi pi-map-marker'
                    : 'pi pi-building'
            "
            aria-hidden="true"
          />
        </span>

        <div class="min-w-0 flex-1 pt-0.5">
          <!-- Summary line -->
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0" :class="{ 'opacity-70': isCancelled(e.data) }">
              <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                <span
                  class="font-semibold text-ink"
                  :class="{ 'line-through': isCancelled(e.data) }"
                >
                  <template v-if="e.kind === 'call'">
                    Call
                    <i
                      :class="
                        e.data.direction === 'in' ? 'pi pi-arrow-down-left' : 'pi pi-arrow-up-right'
                      "
                      class="text-[10px] text-mute"
                      :title="e.data.direction === 'in' ? 'Incoming' : 'Outgoing'"
                      aria-hidden="true"
                    />
                  </template>
                  <template v-else-if="e.kind === 'action'">
                    Planned {{ humanize(e.data.type) }}
                  </template>
                  <template v-else>{{ humanize(e.data.type) }} visit</template>
                </span>
                <StatusTag v-if="isCancelled(e.data)" value="cancelled" />
                <StatusTag
                  v-else-if="e.kind === 'visit'"
                  :value="e.data.is_completed ? 'completed' : 'scheduled'"
                />
                <StatusTag v-else-if="e.kind === 'action'" :value="e.data.state" />
                <span v-if="e.data.edited" class="text-xs italic text-mute" :title="e.data.edit_reason">
                  edited
                </span>
              </p>
              <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-mute">
                <span>{{ formatDateTime(e.at) }}</span>
                <span v-if="e.kind === 'visit' && e.data.unit">
                  · {{ e.data.unit.reference }}
                  <template v-if="e.data.unit.property_type">({{ e.data.unit.property_type }})</template>
                </span>
                <span v-if="e.data.agent">· {{ e.data.agent.name }}</span>
                <span v-if="e.data.assigned_to">· {{ e.data.assigned_to.name }}</span>
                <span v-if="e.data.outcome">· {{ e.data.outcome.label }}</span>
              </p>
            </div>
            <span class="flex shrink-0 items-center gap-1">
              <Button
                v-if="e.kind === 'visit' && !isCancelled(e.data) && canComplete(e.data) && !e.data.is_completed"
                label="Complete"
                icon="pi pi-check"
                size="small"
                outlined
                @click="completing = e.data"
              />
              <Button
                :icon="isExpanded(e) ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"
                text
                rounded
                size="small"
                severity="secondary"
                :aria-label="isExpanded(e) ? 'Collapse details' : 'Expand details'"
                @click="toggle(e)"
              />
            </span>
          </div>

          <p
            v-if="e.data.notes && !isExpanded(e)"
            class="mt-1 line-clamp-2 whitespace-pre-line text-sm text-mute"
          >
            {{ e.data.notes }}
          </p>

          <!-- Expanded: the full detail of the log -->
          <div
            v-if="isExpanded(e)"
            class="mt-2 space-y-3 rounded-xl border border-line bg-surface-50 p-3 text-sm dark:bg-surface-800/50"
          >
            <dl class="grid gap-x-6 gap-y-1.5 sm:grid-cols-2">
              <template v-if="e.kind === 'call'">
                <div class="flex justify-between gap-2">
                  <dt class="text-mute">Direction</dt>
                  <dd class="text-ink">{{ e.data.direction === 'in' ? 'Incoming' : 'Outgoing' }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                  <dt class="text-mute">Called at</dt>
                  <dd class="text-ink">{{ formatDateTime(e.data.called_at) }}</dd>
                </div>
                <div v-if="e.data.topics?.length" class="flex justify-between gap-2 sm:col-span-2">
                  <dt class="text-mute">Topics</dt>
                  <dd class="text-right text-ink">{{ e.data.topics.join(', ') }}</dd>
                </div>
              </template>

              <template v-else-if="e.kind === 'visit'">
                <div class="flex justify-between gap-2">
                  <dt class="text-mute">Scheduled</dt>
                  <dd class="text-ink">{{ formatDateTime(e.data.scheduled_at) }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                  <dt class="text-mute">Completed</dt>
                  <dd class="text-ink">{{ e.data.completed_at ? formatDateTime(e.data.completed_at) : '—' }}</dd>
                </div>
                <div v-if="e.data.unit" class="flex justify-between gap-2 sm:col-span-2">
                  <dt class="text-mute">Property</dt>
                  <dd class="text-right text-ink">
                    {{
                      [
                        e.data.unit.reference,
                        e.data.unit.property_type,
                        e.data.unit.floor,
                        e.data.unit.area_sqm ? `${e.data.unit.area_sqm} m²` : null,
                      ]
                        .filter(Boolean)
                        .join(' · ')
                    }}
                    <template v-if="e.data.unit.location?.name"> — {{ e.data.unit.location.name }}</template>
                  </dd>
                </div>
                <div v-if="e.data.checklist?.length" class="flex justify-between gap-2 sm:col-span-2">
                  <dt class="text-mute">Checklist</dt>
                  <dd class="text-right text-ink">{{ checklistLabels(e.data.checklist).join(', ') }}</dd>
                </div>
                <div v-if="e.data.type === 'in_site' && mapsUrl(e.data)" class="sm:col-span-2">
                  <a
                    :href="mapsUrl(e.data)"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center gap-1.5 text-primary-600 hover:underline dark:text-primary-400"
                  >
                    <i class="pi pi-map" aria-hidden="true" /> Open the site in Google Maps
                  </a>
                </div>
              </template>

              <template v-else>
                <div class="flex justify-between gap-2">
                  <dt class="text-mute">Due</dt>
                  <dd class="text-ink">{{ formatDateTime(e.data.due_at) }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                  <dt class="text-mute">Completed</dt>
                  <dd class="text-ink">{{ e.data.completed_at ? formatDateTime(e.data.completed_at) : '—' }}</dd>
                </div>
              </template>

              <div v-if="e.data.agent || e.data.assigned_to" class="flex justify-between gap-2">
                <dt class="text-mute">{{ e.kind === 'action' ? 'Assigned to' : 'Agent' }}</dt>
                <dd class="text-ink">{{ e.data.agent?.name ?? e.data.assigned_to?.name }}</dd>
              </div>
              <div v-if="e.data.outcome" class="flex justify-between gap-2">
                <dt class="text-mute">Outcome</dt>
                <dd class="text-ink">{{ e.data.outcome.label }}</dd>
              </div>
              <div v-if="e.data.created_at" class="flex justify-between gap-2">
                <dt class="text-mute">Logged</dt>
                <dd class="text-ink">{{ formatDateTime(e.data.created_at) }}</dd>
              </div>
              <div v-if="isCancelled(e.data) && e.data.cancellation_reason" class="flex justify-between gap-2 sm:col-span-2">
                <dt class="text-mute">Cancelled because</dt>
                <dd class="text-right font-medium text-danger">{{ e.data.cancellation_reason }}</dd>
              </div>
            </dl>

            <p v-if="e.data.notes" class="whitespace-pre-line border-t border-line pt-2 text-ink">
              {{ e.data.notes }}
            </p>

            <!-- Previous versions: the cancelled originals this log replaced. -->
            <div v-if="e.data.previous_versions?.length" class="border-t border-line pt-2">
              <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-mute">
                Previous versions
              </p>
              <ol class="space-y-1.5">
                <li
                  v-for="v in e.data.previous_versions"
                  :key="v.id"
                  class="rounded-lg border border-dashed border-line px-2.5 py-2 opacity-75"
                >
                  <p class="flex flex-wrap items-center gap-x-2 text-xs">
                    <StatusTag value="cancelled" />
                    <span class="text-mute line-through">
                      {{ formatDateTime(e.kind === 'call' ? v.called_at : e.kind === 'action' ? v.due_at : v.scheduled_at) }}
                      <template v-if="v.agent?.name || v.assigned_to?.name">
                        · {{ v.agent?.name ?? v.assigned_to?.name }}</template
                      >
                      <template v-if="v.outcome"> · {{ v.outcome.label }}</template>
                    </span>
                    <span v-if="v.cancellation_reason" class="text-mute">
                      — {{ v.cancellation_reason }}
                    </span>
                  </p>
                  <p v-if="v.notes" class="mt-1 whitespace-pre-line text-xs text-mute">
                    {{ v.notes }}
                  </p>
                </li>
              </ol>
            </div>
          </div>
        </div>
      </li>
    </ol>

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
