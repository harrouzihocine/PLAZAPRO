<script setup>
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import draggable from 'vuedraggable'
import Button from 'primevue/button'
import Popover from 'primevue/popover'
import Tag from 'primevue/tag'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import { pipelineApi } from '@/features/pipeline/api'
import PlanDayDialog from '@/features/pipeline/components/PlanDayDialog.vue'
import SuggestAgentsDialog from '@/features/pipeline/components/SuggestAgentsDialog.vue'
import {
  AGENT_STATUS_DOTS,
  AGENT_STATUS_LABEL_KEYS,
  VISIT_STATUS_LABEL_KEYS,
} from '@/features/pipeline/dispatchStatus'
import { getEcho } from '@/composables/useEcho'
import { toastError, toastSuccess } from '@/composables/useConfirm'
import { copyToClipboard } from '@/composables/useClipboard'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { formatDateTime, humanize, intlLocale, todayInput } from '@/utils/format'
import { i18n, t } from '@/i18n'

// The dispatch board: unassigned in-site plans in the PENDING strip, and one
// row per field agent × the shown week. Drag a pending task onto an agent's
// day (or between agents / back to pending), then Save — the moves are applied
// together, the agent and the project's contributors are notified.
// Only in-site items drag; calls and office visits show for workload context.
// Drops are allowed from today onwards only.
//
// Time is a first-class dimension: every card wears its time chip (click to
// set the exact HH:mm), and a day header zooms into DAY VIEW — the same agents
// as rows but one column per hour, so dropping a card pins it to that hour.

const loading = ref(true)
const saving = ref(false)
const weekStart = ref(null) // 'YYYY-MM-DD' (Monday)
const agents = ref([])
const pending = ref([]) // draggable list (pending strip)
// Undone in-site visits whose slot already passed (any week): the grid's past
// cells are forbidden history, so this rail is where overdue work waits to be
// dragged onto a fresh upcoming day/hour (or back to the pending pool).
const overdue = ref([])
const cells = ref({}) // `${agentId}|${day}` -> draggable list (week view)
// One FINAL position per card (`kind:id` → move): dragging the same card
// several times before saving must express only where it ended up — posting
// every intermediate hop would assign/notify agents the card merely passed by.
const moves = ref(new Map())

// Board | live map | mileage tabs — heavy chunks only load on demand.
const DispatchMapTab = defineAsyncComponent(
  () => import('@/features/pipeline/components/DispatchMapTab.vue'),
)
const MileageTab = defineAsyncComponent(
  () => import('@/features/pipeline/components/MileageTab.vue'),
)
const tab = ref('board')

// The assignment assist: ranked agents for one pending plan. Picking records
// a normal board move — the dispatcher still presses Save.
const suggestVisible = ref(false)
const suggestItem = ref(null)

function openSuggest(item) {
  suggestItem.value = item
  suggestVisible.value = true
}

// A pool card landing on an agent — the shared half of every assist (suggest
// dialog, map panel, day optimizer). Mirrors a drag: records the move, the
// card leaves the pool and lands on the agent's day. Save still applies.
function placeOnAgent(item, agentId) {
  const today = localToday()
  const day = (item.due_at ?? '').slice(0, 10) >= today ? item.due_at.slice(0, 10) : today
  const move = { kind: 'action', id: item.id, agent_id: agentId, due_date: day }
  if (item.time) move.due_time = item.time
  recordMove(item, move)
  pending.value = pending.value.filter((el) => el !== item)
  item.type = 'in_site'
  item.agent_id = agentId
  item.day = day
  item.at = `${day}T${item.time ?? '09:00'}:00`
  const key = cellKey(agentId, day)
  if (!cells.value[key]) cells.value[key] = []
  cells.value[key].push(item)
  cells.value[key].sort(byTime)
  if (viewDay.value === day) showDay(day) // re-bucket the zoomed hours
}

function onSuggestPick(agentId) {
  const item = suggestItem.value
  suggestVisible.value = false
  if (!item) return
  placeOnAgent(item, agentId)
  toastSuccess(t('dispatch.suggestRecorded'))
}

// The map panel's "assign the plan behind this pin to this agent".
function onMapAssign({ actionId, agentId }) {
  const item = pending.value.find((el) => el.id === actionId)
  if (item) {
    placeOnAgent(item, agentId)
    toastSuccess(t('dispatch.suggestRecorded'))
    return
  }
  // The pool card isn't loaded (another session assigned meanwhile) — apply
  // the move directly through the standard endpoint.
  pipelineApi
    .dispatchAssign([{ kind: 'action', id: actionId, agent_id: agentId, due_date: localToday() }])
    .then(() => {
      toastSuccess(t('dispatch.saved'))
      load()
    })
    .catch((e) => toastError(e.response?.data?.message ?? t('dispatch.saveFailed')))
}

// The day optimizer: every proposed stop becomes an ordinary recorded move.
const planVisible = ref(false)

function onPlanApply(stops) {
  let recorded = 0
  for (const stop of stops) {
    const item = pending.value.find((el) => el.id === stop.action_id)
    if (!item) continue
    placeOnAgent(item, stop.agent_id)
    recorded++
  }
  if (recorded) toastSuccess(t('dispatch.planDayRecorded', { n: recorded }))
}

const agentStatusDot = (s) => AGENT_STATUS_DOTS[s] ?? AGENT_STATUS_DOTS.off_duty
const agentStatusLabel = (s) => t(AGENT_STATUS_LABEL_KEYS[s] ?? AGENT_STATUS_LABEL_KEYS.off_duty)
const visitStatusLabel = (s) => t(VISIT_STATUS_LABEL_KEYS[s] ?? VISIT_STATUS_LABEL_KEYS.assigned)
// The in-between lifecycle steps get a dot on the grid card (assigned = none,
// done = the existing strike-through).
const VISIT_DOT = { accepted: 'bg-violet-500', en_route: 'bg-amber-500', arrived: 'bg-sky-500' }
// A pool plan pointing at a site with no map pin — GPS features degrade there.
const hasUnpinnedSite = (el) => (el.sites ?? []).some((site) => !site.maps_url)

// Live layer over Reverb: agent dots recolor, cards advance — no polling.
let liveChannel = null
function onAgentLive(e) {
  const agent = agents.value.find((a) => a.id === e.userId)
  if (agent) agent.status = e.status
}
function onVisitLive(e) {
  // Grid + overdue share object references with the zoomed hour buckets, so
  // patching these two covers every rendering.
  for (const list of Object.values(cells.value)) {
    const item = list.find((i) => i.kind === 'visit' && i.id === e.visit_id)
    if (item) item.status = e.status
  }
  const stale = overdue.value.find((i) => i.kind === 'visit' && i.id === e.visit_id)
  if (stale) stale.status = e.status
}
onMounted(() => {
  const echo = getEcho()
  if (!echo) return
  liveChannel = echo.private('dispatch')
  liveChannel.listen('.agent.duty', onAgentLive)
  liveChannel.listen('.agent.position', onAgentLive)
  liveChannel.listen('.visit.lifecycle', onVisitLive)
})
onBeforeUnmount(() => {
  if (liveChannel) getEcho()?.leave('dispatch')
})

// The board works in Algerian calendar dates: the backend (app timezone
// Africa/Algiers) produces week_start, every item's `day` + `time`, and the
// "today onwards" guard in local wall-clock — so "today" must be the browser's
// local date too (toISOString would give the UTC date, a day back before 01:00).
// addDays is pure Y-m-d string arithmetic (parse at UTC midnight, add, slice)
// — timezone-neutral on purpose.
function addDays(isoDate, n) {
  const d = new Date(isoDate + 'T00:00:00Z')
  d.setUTCDate(d.getUTCDate() + n)
  return d.toISOString().slice(0, 10)
}
const localToday = () => todayInput()

const days = computed(() => {
  if (!weekStart.value) return []
  const today = localToday()
  return Array.from({ length: 7 }, (_, i) => {
    const date = addDays(weekStart.value, i)
    return {
      date,
      label: new Date(date + 'T00:00:00Z').toLocaleDateString(intlLocale(), {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
      }),
      isPast: date < today, // ISO YYYY-MM-DD compares chronologically as strings
      isToday: date === today,
    }
  })
})

const cellKey = (agentId, day) => `${agentId}|${day}`
const cellList = (agentId, day) => cells.value[cellKey(agentId, day)] ?? []
const hasChanges = computed(() => moves.value.size > 0)

// Cards sort by their time within a cell; untimed ones float to the top.
const byTime = (a, b) => (a.time ?? '').localeCompare(b.time ?? '')

// A pool plan whose due day already passed — flagged red so the dispatcher
// assigns it first (the ISO date part compares as a plain string).
const isDuePast = (el) => (el.due_at ?? '').slice(0, 10) < localToday()
const itemHour = (item) => (item.time ? Number.parseInt(item.time.slice(0, 2), 10) : null)
const pad2 = (n) => String(n).padStart(2, '0')

async function load(week = weekStart.value) {
  loading.value = true
  moves.value = new Map()
  // Remember the zoomed day across a save/reload; the stale hour grid itself
  // is rebuilt below from the fresh cells.
  const zoomedDay = viewDay.value
  viewDay.value = null
  hourCells.value = {}
  try {
    const data = await pipelineApi.dispatchBoard(week)
    weekStart.value = data.week_start
    agents.value = data.agents
    pending.value = data.pending
    overdue.value = data.overdue ?? []
    const grid = {}
    for (const a of data.agents) for (const d of dayDates(data.week_start)) grid[cellKey(a.id, d)] = []
    for (const item of data.items) {
      const key = cellKey(item.agent_id, item.day)
      if (grid[key]) grid[key].push(item)
    }
    for (const key of Object.keys(grid)) grid[key].sort(byTime)
    cells.value = grid
    if (zoomedDay && dayDates(data.week_start).includes(zoomedDay)) showDay(zoomedDay)
  } finally {
    loading.value = false
  }
}

function dayDates(start) {
  return Array.from({ length: 7 }, (_, i) => addDays(start, i))
}

function shiftWeek(deltaDays) {
  load(addDays(weekStart.value, deltaDays))
}

onMounted(() => load(null))
useRefreshable(() => load()) // reload the shown week (pull-to-refresh + reconnect self-heal)

// --- Day view (hour columns) ------------------------------------------------
// Zooming into a day re-buckets that day's cards per hour: `hourCells` becomes
// the draggable source of truth (`${agentId}|${hour}`, 'u' = untimed) until the
// view flattens back into `cells` on exit / day switch. The hour range covers
// the working day and stretches to fit any out-of-hours card.
const DAY_START = 7
const DAY_END = 19
const viewDay = ref(null) // 'YYYY-MM-DD' when zoomed, null = week view
const hourCells = ref({})
const dayHours = ref([])
const dayHasUntimed = ref(false)

function showDay(day) {
  flattenDay() // leaving another zoomed day: fold its buckets back first

  let min = DAY_START
  let max = DAY_END
  let untimed = false
  for (const a of agents.value) {
    for (const item of cellList(a.id, day)) {
      const h = itemHour(item)
      if (h === null) untimed = true
      else {
        min = Math.min(min, h)
        max = Math.max(max, h)
      }
    }
  }
  const hours = Array.from({ length: max - min + 1 }, (_, i) => min + i)

  const grid = {}
  for (const a of agents.value) {
    grid[`${a.id}|u`] = []
    for (const h of hours) grid[`${a.id}|${h}`] = []
    for (const item of cellList(a.id, day)) {
      const h = itemHour(item)
      grid[`${a.id}|${h === null ? 'u' : h}`].push(item)
    }
  }

  dayHours.value = hours
  dayHasUntimed.value = untimed
  hourCells.value = grid
  viewDay.value = day
}

// Fold the zoomed day's hour buckets back into its week cell (cards may have
// changed agent/hour or arrived from the pool while zoomed).
function flattenDay() {
  if (!viewDay.value) return
  for (const a of agents.value) {
    const merged = Object.entries(hourCells.value)
      .filter(([key]) => key.startsWith(`${a.id}|`))
      .flatMap(([, list]) => list)
      .sort(byTime)
    cells.value[cellKey(a.id, viewDay.value)] = merged
  }
}

function exitDay() {
  flattenDay()
  viewDay.value = null
}

// Day-to-day arrows stay within the loaded week (the board loads one week).
const canPrevDay = computed(() => viewDay.value && viewDay.value > weekStart.value)
const canNextDay = computed(() => viewDay.value && viewDay.value < addDays(weekStart.value, 6))
function shiftDay(n) {
  const d = addDays(viewDay.value, n)
  if (d >= weekStart.value && d <= addDays(weekStart.value, 6)) showDay(d)
}
const dayLabel = computed(() => days.value.find((d) => d.date === viewDay.value)?.label ?? viewDay.value)

// The grid columns of the current view: week → the 7 days, day → the hours
// (plus a read-only "No time" gutter when untimed cards exist that day).
const columns = computed(() => {
  if (!viewDay.value) {
    return days.value.map((d) => ({
      kind: 'day',
      key: d.date,
      label: d.label,
      isPast: d.isPast,
      isToday: d.isToday,
    }))
  }
  const today = localToday()
  const nowHour = new Date().getHours()
  return [
    ...(dayHasUntimed.value ? [{ kind: 'untimed', key: 'u', label: t('dispatch.noTime') }] : []),
    ...dayHours.value.map((h) => ({
      kind: 'hour',
      key: h,
      label: `${pad2(h)}:00`,
      isToday: viewDay.value === today && h === nowHour,
      // A slot already behind the clock is forbidden ground — a past day's
      // hours entirely, and today's hours before the current one.
      isPast: viewDay.value < today || (viewDay.value === today && h < nowHour),
    })),
  ]
})

function listFor(agentId, col) {
  return viewDay.value
    ? (hourCells.value[`${agentId}|${col.key}`] ?? [])
    : (cells.value[cellKey(agentId, col.key)] ?? [])
}

// Whatever the view, an agent's row counts every card it shows.
function agentCount(agentId) {
  return columns.value.reduce((n, col) => n + listFor(agentId, col).length, 0)
}

// --- Drag & drop ----------------------------------------------------------
// vuedraggable moves items between the bound lists; we record the intent and
// send everything on Save. `checkMove`-style rejection: past days don't accept.
function recordMove(item, move) {
  const next = new Map(moves.value)
  next.set(`${move.kind}:${move.id}`, move)
  moves.value = next
}

const isMoved = (item) => moves.value.has(`${item.kind}:${item.id}`)

function onDropToCell(evt, agentId, col) {
  const item = evt.added?.element
  if (!item) return
  const day = viewDay.value ?? col.key
  const move = {
    kind: item.kind === 'action' ? 'action' : 'visit',
    id: item.id,
    agent_id: agentId,
    due_date: day,
  }
  if (viewDay.value && col.kind === 'hour') {
    // Dropped on an hour slot: pin that hour — but a same-hour move (from
    // another agent's row) keeps the card's exact minutes.
    move.due_time = itemHour(item) === col.key && item.time ? item.time : `${pad2(col.key)}:00`
  } else if (item.time) {
    // Week view keeps the card's time across day/agent moves — and re-sending
    // it means a later drop can never wipe an earlier time chip edit.
    move.due_time = item.time
  }
  recordMove(item, move)
  item.agent_id = agentId
  item.day = day
  if (move.due_time) item.time = move.due_time
  item.at = `${day}T${item.time ?? '09:00'}:00`
}

function onDropToPending(evt) {
  const item = evt.added?.element
  if (!item) return
  recordMove(item, { kind: item.kind === 'action' ? 'action' : 'visit', id: item.id, agent_id: null })
}

// Pending tasks always drag; grid items only when the server said so — and a
// visit may only return to the pending strip when its plan is still open.
// NO drag delay, on purpose: a touch delay (delayOnTouchOnly) cancels the drag
// when the finger moves during the hold — SortableJS divides the threshold by
// devicePixelRatio, ~1px of tolerance on a phone — so a natural press-and-move
// never starts and the board feels dead. Instant drag works on mouse AND touch.
const dragOptions = { animation: 150, group: 'dispatch' }

function canReceive(col) {
  if (!viewDay.value) return !col.isPast
  // Hour slots accept unless the clock already passed them; the untimed gutter
  // is display-only (dropping "onto no time" would be a lie — drag out of it).
  return col.kind === 'hour' && !col.isPast
}

function checkMove(evt) {
  const el = evt.draggedContext?.element
  if (!el) return false
  // Pool plans have no `draggable` flag (always movable); GRID items — visits
  // and the context-only call plans — carry it and must honour it, whatever
  // their kind, or a rejected card poisons the whole saved batch (422).
  if ('draggable' in el && !el.draggable) return false
  const toPending = evt.to?.classList?.contains('pending-zone')
  if (el.kind === 'action') return true
  return !toPending || el.can_unassign
}

async function save() {
  if (!moves.value.size) return
  saving.value = true
  try {
    await pipelineApi.dispatchAssign([...moves.value.values()])
    toastSuccess(t('dispatch.saved'))
    await load()
  } catch (e) {
    toastError(e.response?.data?.message ?? t('dispatch.saveFailed'))
  } finally {
    saving.value = false
  }
}

// --- Time chip editor -------------------------------------------------------
// Click a card's time chip to set the exact HH:mm without dragging (the
// "drop, then decide the time" path). Recorded as a move like any drag.
const timeEditRef = ref(null)
const timeEditItem = ref(null)
const timeEditValue = ref('')
const timeEditValid = computed(() => /^\d{2}:\d{2}$/.test(timeEditValue.value))

// Editable time: any card the board may move (pool plans carry no `draggable`
// flag — a freshly dropped one is editable right away), today onwards.
const canEditTime = (item) =>
  ('draggable' in item ? item.draggable : true) && !!item.day && item.day >= localToday()

function openTimeEdit(event, item) {
  timeEditItem.value = item
  timeEditValue.value = item.time ?? ''
  timeEditRef.value?.show(event)
}

function applyTimeEdit() {
  const item = timeEditItem.value
  const time = timeEditValue.value
  if (!item || !timeEditValid.value) return
  recordMove(item, {
    kind: item.kind === 'action' ? 'action' : 'visit',
    id: item.id,
    agent_id: item.agent_id,
    due_date: item.day,
    due_time: time,
  })
  item.time = time
  item.at = `${item.day}T${time}:00`
  timeEditRef.value?.hide()
  // Zoomed: the card may now belong to another hour bucket.
  if (viewDay.value) showDay(viewDay.value)
}

// --- Details popover -------------------------------------------------------
const detailsRef = ref(null)
const detailsItem = ref(null)
function showDetails(event, item) {
  detailsItem.value = item
  detailsRef.value?.show(event)
}

const typeLabel = (type) => {
  const key = type === 'in_site' ? 'status.in_site_visit' : type === 'office' ? 'status.office_visit' : type === 'call' ? 'pipeline.typeCall' : `status.${type}`
  return i18n.global.te(key) ? t(key) : humanize(type)
}

const TYPE_TONES = {
  in_site: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
  office: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
  call: 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
}
const TYPE_ICONS = { in_site: 'pi pi-map-marker', office: 'pi pi-building', call: 'pi pi-phone' }
</script>

<template>
  <div>
    <PageHeader
:title="$t('dispatch.title')"
      :subtitle="$t('dispatch.subtitle')"
    >
      <template #actions>
        <!-- Board | live map -->
        <Button
          :label="$t('dispatch.boardTab')"
          icon="pi pi-table"
          size="small"
          :severity="tab === 'board' ? 'primary' : 'secondary'"
          :outlined="tab !== 'board'"
          @click="tab = 'board'"
        />
        <Button
          :label="$t('dispatch.mapTab')"
          icon="pi pi-map"
          size="small"
          :severity="tab === 'map' ? 'primary' : 'secondary'"
          :outlined="tab !== 'map'"
          @click="tab = 'map'"
        />
        <Button
          :label="$t('dispatch.mileageTab')"
          icon="pi pi-gauge"
          size="small"
          :severity="tab === 'mileage' ? 'primary' : 'secondary'"
          :outlined="tab !== 'mileage'"
          @click="tab = 'mileage'"
        />
        <!-- The day optimizer: propose a split of the pending pool across the
             on-duty agents (review-and-apply, recorded as board moves). -->
        <Button
          v-if="tab === 'board'"
          :label="$t('dispatch.planDay')"
          icon="pi pi-sparkles"
          size="small"
          severity="secondary"
          outlined
          :disabled="loading || !pending.length"
          @click="planVisible = true"
        />
        <!-- Week navigation, or day navigation when zoomed into hours. -->
        <template v-if="tab === 'board' && !viewDay">
          <Button icon="pi pi-chevron-left" severity="secondary" text :aria-label="$t('pipeline.previousWeek')" @click="shiftWeek(-7)" />
          <span class="num self-center text-sm font-medium text-ink">{{ $t('dispatch.weekOf', { date: weekStart }) }}</span>
          <Button icon="pi pi-chevron-right" severity="secondary" text :aria-label="$t('pipeline.nextWeek')" @click="shiftWeek(7)" />
        </template>
        <template v-else-if="tab === 'board'">
          <Button
            icon="pi pi-chevron-left"
            severity="secondary"
            text
:aria-label="$t('dispatch.previousDay')"
            :disabled="!canPrevDay"
            @click="shiftDay(-1)"
          />
          <span class="num self-center text-sm font-medium text-ink">{{ dayLabel }}</span>
          <Button
            icon="pi pi-chevron-right"
            severity="secondary"
            text
:aria-label="$t('dispatch.nextDay')"
            :disabled="!canNextDay"
            @click="shiftDay(1)"
          />
          <Button :label="$t('dispatch.weekView')" icon="pi pi-calendar" severity="secondary" outlined @click="exitDay" />
        </template>
        <Button
:label="$t('dispatch.saveAssignments')"
          icon="pi pi-check"
          :disabled="!hasChanges"
          :loading="saving"
          @click="save"
        />
      </template>
    </PageHeader>

    <!-- Live map tab: agents + today's sites + replay (Leaflet, lazy). -->
    <DispatchMapTab v-if="tab === 'map'" @assign="onMapAssign" />

    <!-- Mileage tab: km per agent per day, from the duty paths. -->
    <MileageTab v-else-if="tab === 'mileage'" />

    <template v-else>
    <!-- Pending pool -->
    <SectionCard
      :title="$t('dispatch.pendingTitle', { n: pending.length })"
      icon="pi pi-inbox"
      class="mb-5"
    >
      <p v-if="loading" class="py-4 text-center text-sm text-mute">{{ $t('common.loading') }}</p>
      <template v-else>
        <p v-if="!pending.length" class="mb-2 text-sm text-mute">
          <i class="pi pi-check-circle me-1 text-success" aria-hidden="true" />
          {{ $t('dispatch.noPending') }}
        </p>
        <draggable
          v-model="pending"
          item-key="id"
          v-bind="dragOptions"
          :move="checkMove"
          class="pending-zone flex min-h-[44px] flex-wrap gap-2 rounded-xl border border-dashed border-line p-2"
          @change="onDropToPending"
        >
          <template #item="{ element }">
            <div
              class="flex max-w-xs cursor-grab flex-col gap-0.5 rounded-xl border border-dashed border-amber-300 bg-amber-50 px-3 py-2 text-sm active:cursor-grabbing dark:border-amber-500/40 dark:bg-amber-500/10"
            >
              <div class="flex items-center gap-2">
                <i class="pi pi-map-marker text-amber-600 dark:text-amber-400" aria-hidden="true" />
                <span class="min-w-0 flex-1 truncate font-medium text-ink">
                  {{ element.client ?? '—' }}
                </span>
                <button
                  type="button"
                  class="text-primary-600 transition-colors hover:text-primary-700 dark:text-primary-400"
                  :aria-label="$t('dispatch.suggest')"
                  :title="$t('dispatch.suggest')"
                  @click.stop="openSuggest(element)"
                >
                  <i class="pi pi-bolt" aria-hidden="true" />
                </button>
                <button
                  type="button"
                  class="text-mute transition-colors hover:text-ink"
:aria-label="$t('dispatch.taskDetails')"
                  @click.stop="showDetails($event, element)"
                >
                  <i class="pi pi-info-circle" aria-hidden="true" />
                </button>
              </div>
              <!-- Which site(s) to visit — the shortlisted properties' locations. -->
              <span v-if="element.sites?.length" class="truncate text-xs text-ink">
                <i class="pi pi-building text-[10px]" aria-hidden="true" />
                {{ element.sites.map((s) => s.name).join(', ') }}
                <!-- A site with no map pin: distance/geofence/ETA degrade there. -->
                <i
                  v-if="hasUnpinnedSite(element)"
                  class="pi pi-exclamation-triangle text-[10px] text-amber-500"
                  :title="$t('dispatch.noPin')"
                  aria-hidden="true"
                />
              </span>
              <span
                class="num text-xs"
                :class="isDuePast(element) ? 'font-medium text-danger' : 'text-mute'"
              >
                {{ $t('pipeline.due', { date: formatDateTime(element.due_at) }) }}
              </span>
            </div>
          </template>
        </draggable>
      </template>
    </SectionCard>

    <!-- Overdue rail: undone in-site visits whose slot already passed (any
         week). Past grid cells are forbidden ground, so this is where that
         work waits — drag a card onto an upcoming day/hour to reschedule it,
         or back to the pending pool to un-assign. -->
    <SectionCard
      v-if="overdue.length"
      :title="$t('dispatch.overdueTitle', { n: overdue.length })"
      icon="pi pi-history"
      class="mb-5"
    >
      <draggable
        :list="overdue"
        item-key="id"
        v-bind="dragOptions"
        :move="checkMove"
        :group="{ name: 'dispatch', put: false, pull: true }"
        class="flex min-h-[44px] flex-wrap gap-2 rounded-xl border border-dashed border-red-300 bg-red-50/50 p-2 dark:border-red-500/40 dark:bg-red-500/5"
      >
        <template #item="{ element }">
          <div
            class="flex max-w-xs cursor-grab flex-col gap-0.5 rounded-xl border border-dashed border-red-300 bg-red-50 px-3 py-2 text-sm active:cursor-grabbing dark:border-red-500/40 dark:bg-red-500/10"
            :class="isMoved(element) ? 'ring-1 ring-primary' : ''"
          >
            <div class="flex items-center gap-2">
              <i class="pi pi-exclamation-triangle text-red-600 dark:text-red-400" aria-hidden="true" />
              <span class="min-w-0 flex-1 truncate font-medium text-ink">
                {{ element.client ?? '—' }}
              </span>
              <span v-if="element.unit" class="num shrink-0 text-xs text-mute">{{ element.unit }}</span>
              <button
                type="button"
                class="text-mute transition-colors hover:text-ink"
  :aria-label="$t('dispatch.taskDetails')"
                @click.stop="showDetails($event, element)"
              >
                <i class="pi pi-info-circle" aria-hidden="true" />
              </button>
            </div>
            <span class="num text-xs text-red-700 dark:text-red-300">
              {{ $t('dispatch.was', { date: formatDateTime(element.at) }) }}
            </span>
          </div>
        </template>
      </draggable>
      <p class="mt-2 text-xs text-mute">
        {{ $t('dispatch.overdueHint') }}
      </p>
    </SectionCard>

    <!-- Agents × weekdays grid (or × hours when zoomed into a day) -->
    <SectionCard flush>
      <p v-if="loading" class="py-10 text-center text-sm text-mute">{{ $t('dispatch.loadingWeek') }}</p>
      <div v-else class="overflow-x-auto">
        <table class="w-full border-collapse text-sm" :class="viewDay ? 'min-w-[1100px]' : 'min-w-[900px]'">
          <thead>
            <tr>
              <th class="sticky start-0 z-10 border-b border-line bg-card px-3 py-2 text-start text-xs font-semibold uppercase tracking-wide text-mute">
                {{ $t('clients.agent') }}
              </th>
              <th
                v-for="col in columns"
                :key="col.key"
                class="border-b border-s border-line px-2 py-2 text-start text-xs font-semibold uppercase tracking-wide"
                :class="col.isToday ? 'bg-highlight text-primary-700 dark:text-primary-300' : col.isPast ? 'text-mute/50' : 'text-mute'"
              >
                <!-- A day header zooms into that day's hours; past slots wear
                     the ban mark — history only, no drops. -->
                <button
                  v-if="col.kind === 'day'"
                  type="button"
                  class="group inline-flex items-center gap-1.5 uppercase tracking-wide transition-colors hover:text-ink"
                  :title="col.isPast ? $t('dispatch.pastDayTitle', { day: col.label }) : $t('dispatch.zoomDayTitle', { day: col.label })"
                  @click="showDay(col.key)"
                >
                  <i v-if="col.isPast" class="pi pi-ban text-[10px] text-danger/60" aria-hidden="true" />
                  {{ col.label }}
                  <i class="pi pi-search-plus text-[10px] opacity-40 transition-opacity group-hover:opacity-100" aria-hidden="true" />
                </button>
                <template v-else>
                  <i v-if="col.isPast" class="pi pi-ban me-1 text-[10px] text-danger/60" aria-hidden="true" />
                  {{ col.label }}
                </template>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="agent in agents" :key="agent.id" class="align-top">
              <th class="sticky start-0 z-10 border-b border-line bg-card px-3 py-2 text-start font-medium text-ink">
                <span class="inline-flex items-center gap-1.5">
                  <!-- The live duty dot: green available, amber en route,
                       sky on site, grey off duty (recolors over Reverb). -->
                  <span
                    class="inline-block h-2 w-2 shrink-0 rounded-full"
                    :class="agentStatusDot(agent.status)"
                    :title="agentStatusLabel(agent.status)"
                  />
                  {{ agent.name }}
                </span>
                <span class="num mt-0.5 block text-xs font-normal text-mute">
                  {{ agentCount(agent.id) }} · {{ viewDay ? $t('dispatch.thisDay') : $t('dispatch.thisWeek') }}
                  <template v-if="agent.today_left"> · {{ $t('dispatch.todayLeft', { n: agent.today_left }) }}</template>
                </span>
              </th>
              <td
                v-for="col in columns"
                :key="col.key"
                class="border-b border-s border-line p-1.5"
                :class="{
                  'forbidden-zone bg-surface-50 dark:bg-surface-900/40': col.isPast,
                  'bg-surface-50 dark:bg-surface-900/40': col.kind === 'untimed',
                  'bg-highlight/40': col.isToday,
                }"
              >
                <draggable
                  :list="listFor(agent.id, col)"
                  item-key="id"
                  v-bind="dragOptions"
                  :move="checkMove"
                  :group="{ name: 'dispatch', put: canReceive(col), pull: true }"
                  class="min-h-[52px] space-y-1"
                  :class="viewDay ? 'min-w-[72px]' : ''"
                  @change="(evt) => onDropToCell(evt, agent.id, col)"
                >
                  <template #item="{ element }">
                    <div
                      class="rounded-lg px-2 py-1.5 text-xs"
                      :class="[
                        TYPE_TONES[element.type] ?? 'bg-surface-100 dark:bg-surface-800',
                        element.draggable ? 'cursor-grab active:cursor-grabbing' : 'cursor-default opacity-80',
                        element.is_completed ? 'line-through opacity-60' : '',
                        isMoved(element) ? 'ring-1 ring-primary' : '',
                      ]"
                    >
                      <button
                        type="button"
                        class="flex w-full items-center gap-1.5 text-start"
                        @click="showDetails($event, element)"
                      >
                        <i :class="TYPE_ICONS[element.type]" aria-hidden="true" />
                        <span
                          v-if="element.type === 'in_site' && VISIT_DOT[element.status]"
                          class="inline-block h-1.5 w-1.5 shrink-0 rounded-full"
                          :class="VISIT_DOT[element.status]"
                          :title="visitStatusLabel(element.status)"
                        />
                        <span class="min-w-0 flex-1 truncate font-medium">{{ element.client ?? '—' }}</span>
                        <span v-if="element.unit" class="num shrink-0">{{ element.unit }}</span>
                      </button>
                      <!-- The time chip: the card's hour at a glance; click to set
                           the exact time (recorded as a move, saved with the rest). -->
                      <div class="mt-1 flex items-center">
                        <button
                          v-if="canEditTime(element)"
                          type="button"
                          class="num inline-flex items-center gap-1 rounded-full border border-current px-1.5 py-0.5 text-[10px] leading-none transition-opacity hover:opacity-100 native:max-md:px-2.5 native:max-md:py-1.5 native:max-md:text-xs"
                          :class="element.time ? 'opacity-80' : 'opacity-60'"
                          :aria-label="$t('dispatch.setTimeAria')"
                          :title="$t('dispatch.setExactTime')"
                          @click.stop="openTimeEdit($event, element)"
                        >
                          <i class="pi pi-clock text-[9px]" aria-hidden="true" />
                          {{ element.time ?? $t('dispatch.setTime') }}
                        </button>
                        <span
                          v-else-if="element.time"
                          class="num inline-flex items-center gap-1 px-0.5 text-[10px] leading-none opacity-70"
                        >
                          <i class="pi pi-clock text-[9px]" aria-hidden="true" />
                          {{ element.time }}
                        </span>
                      </div>
                    </div>
                  </template>
                </draggable>
              </td>
            </tr>
            <tr v-if="!agents.length">
              <td :colspan="columns.length + 1" class="px-4 py-8 text-center text-sm text-mute">
                {{ $t('dispatch.noAgents') }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </SectionCard>

    <p class="mt-2 text-xs text-mute">
      {{ $t('dispatch.boardHint') }}
    </p>
    </template>

    <!-- Time chip editor: exact HH:mm without dragging. -->
    <Popover ref="timeEditRef" class="w-60 max-w-[92vw]">
      <form class="space-y-2.5" @submit.prevent="applyTimeEdit">
        <p class="text-xs font-semibold uppercase tracking-wide text-mute">
          {{ $t('common.time') }} — {{ timeEditItem?.client ?? $t('pipeline.typeTask') }}
        </p>
        <input
          v-model="timeEditValue"
          type="time"
          class="w-full rounded-md border border-line bg-card px-3 py-2 text-sm text-ink outline-none transition-colors focus:border-primary"
:aria-label="$t('common.time')"
        />
        <div class="flex justify-end gap-2">
          <Button :label="$t('common.cancel')" size="small" text severity="secondary" @click="timeEditRef?.hide()" />
          <Button type="submit" :label="$t('dispatch.setTime')" size="small" :disabled="!timeEditValid" />
        </div>
      </form>
    </Popover>

    <!-- Task details -->
    <Popover ref="detailsRef" class="w-80 max-w-[92vw]">
      <div v-if="detailsItem" class="space-y-2 text-sm">
        <p class="flex items-center gap-2 font-semibold text-ink">
          <i :class="TYPE_ICONS[detailsItem.type] ?? 'pi pi-map-marker'" aria-hidden="true" />
          {{ typeLabel(detailsItem.type ?? 'in_site') }}
          <Tag v-if="detailsItem.is_completed" :value="$t('status.completed')" severity="secondary" />
        </p>
        <dl class="space-y-1.5">
          <div class="flex justify-between gap-2">
            <dt class="text-mute">{{ $t('clients.client') }}</dt>
            <dd class="text-ink">{{ detailsItem.client ?? '—' }}</dd>
          </div>
          <div class="flex justify-between gap-2">
            <dt class="text-mute">{{ $t('dispatch.when') }}</dt>
            <dd class="num text-ink">{{ formatDateTime(detailsItem.at ?? detailsItem.due_at) }}</dd>
          </div>
          <div v-if="detailsItem.unit" class="flex justify-between gap-2">
            <dt class="text-mute">{{ $t('project.unit') }}</dt>
            <dd class="text-ink">{{ detailsItem.unit }}</dd>
          </div>
          <div v-if="detailsItem.location" class="flex justify-between gap-2">
            <dt class="text-mute">{{ $t('dispatch.site') }}</dt>
            <dd class="text-ink">{{ detailsItem.location }}</dd>
          </div>
        </dl>

        <!-- Pending in-site plans: the properties + sites to visit once assigned. -->
        <div v-if="detailsItem.units?.length" class="border-t border-line pt-2">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-mute">{{ $t('dispatch.toVisit') }}</p>
          <ul class="space-y-1">
            <li
              v-for="(u, i) in detailsItem.units"
              :key="i"
              class="flex items-center justify-between gap-2"
            >
              <span class="num text-ink">{{ u.reference }}</span>
              <span v-if="u.site" class="truncate text-xs text-mute">{{ u.site }}</span>
            </li>
          </ul>
        </div>
        <p
          v-else-if="detailsItem.kind === 'action' && detailsItem.type !== 'call'"
          class="border-t border-line pt-2 text-xs text-mute"
        >
          {{ $t('dispatch.noShortlistedYet') }}
        </p>

        <!-- Per-site Google Maps links for a pending plan (may span sites). -->
        <div v-if="detailsItem.sites?.length" class="flex flex-wrap gap-x-3 gap-y-1">
          <div
            v-for="(s, i) in detailsItem.sites.filter((x) => x.maps_url)"
            :key="i"
            class="inline-flex items-center gap-1"
          >
            <a
              :href="s.maps_url"
              target="_blank"
              rel="noopener"
              class="inline-flex items-center gap-1.5 text-xs text-primary-600 hover:underline dark:text-primary-400"
            >
              <i class="pi pi-map" aria-hidden="true" /> {{ s.name }}
            </a>
            <button
              type="button"
:title="$t('pipeline.copyMapsLink')"
              :aria-label="$t('pipeline.copyMapsLink')"
              class="inline-flex items-center text-xs text-primary-600 hover:underline dark:text-primary-400"
              @click="copyToClipboard(s.maps_url, $t('pipeline.mapsLinkCopied'))"
            >
              <i class="pi pi-copy" aria-hidden="true" />
            </button>
          </div>
        </div>
        <div class="flex items-center gap-3 border-t border-line pt-2">
          <RouterLink
            v-if="detailsItem.link"
            :to="detailsItem.link"
            class="inline-flex items-center gap-1.5 text-primary-600 hover:underline dark:text-primary-400"
          >
            <i class="pi pi-folder-open" aria-hidden="true" /> {{ $t('dispatch.openProject') }}
          </RouterLink>
          <a
            v-if="detailsItem.maps_url"
            :href="detailsItem.maps_url"
            target="_blank"
            rel="noopener"
            class="inline-flex items-center gap-1.5 text-primary-600 hover:underline dark:text-primary-400"
          >
            <i class="pi pi-map" aria-hidden="true" /> Google Maps
          </a>
          <button
            v-if="detailsItem.maps_url"
            type="button"
:title="$t('pipeline.copyMapsLink')"
            :aria-label="$t('pipeline.copyMapsLink')"
            class="inline-flex items-center text-primary-600 hover:underline dark:text-primary-400"
            @click="copyToClipboard(detailsItem.maps_url, $t('pipeline.mapsLinkCopied'))"
          >
            <i class="pi pi-copy" aria-hidden="true" />
          </button>
        </div>
      </div>
    </Popover>

    <!-- The assignment assist (ranked agents for one pending plan). -->
    <SuggestAgentsDialog
      v-model:visible="suggestVisible"
      :action-id="suggestItem?.id ?? null"
      @pick="onSuggestPick"
    />

    <!-- The day optimizer (whole pool split across on-duty agents). -->
    <PlanDayDialog v-model:visible="planVisible" @apply="onPlanApply" />
  </div>
</template>

<style scoped>
/* Past days/hours are forbidden ground: red-hatched, no-drop cursor. The
   faint stripes read in light and dark themes alike (low-alpha red). */
.forbidden-zone {
  background-image: repeating-linear-gradient(
    135deg,
    transparent 0 6px,
    rgb(239 68 68 / 0.07) 6px 12px
  );
  cursor: not-allowed;
}
</style>
