<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import draggable from 'vuedraggable'
import Button from 'primevue/button'
import Popover from 'primevue/popover'
import Tag from 'primevue/tag'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import { pipelineApi } from '@/features/pipeline/api'
import { toastError, toastSuccess } from '@/composables/useConfirm'
import { formatDateTime, humanize } from '@/utils/format'

// The dispatch board: unassigned in-site plans in the PENDING strip, and one
// row per field agent × the 7 days of the shown week. Drag a pending task onto
// an agent's day (or between agents / back to pending), then Save — the moves
// are applied together, the agent and the project's contributors are notified.
// Only in-site items drag; calls and office visits show for workload context.
// Drops are allowed from today onwards only.

const loading = ref(true)
const saving = ref(false)
const weekStart = ref(null) // 'YYYY-MM-DD' (Monday)
const agents = ref([])
const pending = ref([]) // draggable list (pending strip)
const cells = ref({}) // `${agentId}|${day}` -> draggable list
const moves = ref([]) // accumulated drag moves, saved in one batch

const days = computed(() => {
  if (!weekStart.value) return []
  const start = new Date(weekStart.value + 'T00:00:00')
  return Array.from({ length: 7 }, (_, i) => {
    const d = new Date(start)
    d.setDate(start.getDate() + i)
    return {
      date: d.toISOString().slice(0, 10),
      label: d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' }),
      isPast: d < new Date(new Date().toDateString()),
      isToday: d.toDateString() === new Date().toDateString(),
    }
  })
})

const cellKey = (agentId, day) => `${agentId}|${day}`
const cellList = (agentId, day) => cells.value[cellKey(agentId, day)] ?? []
const hasChanges = computed(() => moves.value.length > 0)

async function load(week = weekStart.value) {
  loading.value = true
  moves.value = []
  try {
    const data = await pipelineApi.dispatchBoard(week)
    weekStart.value = data.week_start
    agents.value = data.agents
    pending.value = data.pending
    const grid = {}
    for (const a of data.agents) for (const d of dayDates(data.week_start)) grid[cellKey(a.id, d)] = []
    for (const item of data.items) {
      const key = cellKey(item.agent_id, item.day)
      if (grid[key]) grid[key].push(item)
    }
    cells.value = grid
  } finally {
    loading.value = false
  }
}

function dayDates(start) {
  const s = new Date(start + 'T00:00:00')
  return Array.from({ length: 7 }, (_, i) => {
    const d = new Date(s)
    d.setDate(s.getDate() + i)
    return d.toISOString().slice(0, 10)
  })
}

function shiftWeek(deltaDays) {
  const d = new Date(weekStart.value + 'T00:00:00')
  d.setDate(d.getDate() + deltaDays)
  load(d.toISOString().slice(0, 10))
}

onMounted(() => load(null))

// --- Drag & drop ----------------------------------------------------------
// vuedraggable moves items between the bound lists; we record the intent and
// send everything on Save. `checkMove`-style rejection: past days don't accept.
function onDropToCell(evt, agentId, day) {
  const item = evt.added?.element
  if (!item) return
  moves.value.push({
    kind: item.kind === 'action' ? 'action' : 'visit',
    id: item.id,
    agent_id: agentId,
    due_date: day,
  })
  item.agent_id = agentId
  item.day = day
}

function onDropToPending(evt) {
  const item = evt.added?.element
  if (!item) return
  moves.value.push({ kind: item.kind === 'action' ? 'action' : 'visit', id: item.id, agent_id: null })
}

// Pending tasks always drag; grid items only when the server said so — and a
// visit may only return to the pending strip when its plan is still open.
const dragOptions = { animation: 150, group: 'dispatch' }
const canReceive = (day) => !day.isPast

function checkMove(evt) {
  const el = evt.draggedContext?.element
  if (!el) return false
  const toPending = evt.to?.classList?.contains('pending-zone')
  if (el.kind === 'action') return true
  if (!el.draggable) return false
  return !toPending || el.can_unassign
}

async function save() {
  if (!moves.value.length) return
  saving.value = true
  try {
    await pipelineApi.dispatchAssign(moves.value)
    toastSuccess('Assignments saved — the agents and contributors were notified.')
    await load()
  } catch (e) {
    toastError(e.response?.data?.message ?? 'Could not save the assignments.')
  } finally {
    saving.value = false
  }
}

// --- Details popover -------------------------------------------------------
const detailsRef = ref(null)
const detailsItem = ref(null)
function showDetails(event, item) {
  detailsItem.value = item
  detailsRef.value?.show(event)
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
      title="Dispatch board"
      subtitle="Assign pending in-site visits to field agents — drag onto a day, then save."
    >
      <template #actions>
        <Button icon="pi pi-chevron-left" severity="secondary" text aria-label="Previous week" @click="shiftWeek(-7)" />
        <span class="num self-center text-sm font-medium text-ink">week of {{ weekStart }}</span>
        <Button icon="pi pi-chevron-right" severity="secondary" text aria-label="Next week" @click="shiftWeek(7)" />
        <Button
          label="Save assignments"
          icon="pi pi-check"
          :disabled="!hasChanges"
          :loading="saving"
          @click="save"
        />
      </template>
    </PageHeader>

    <!-- Pending pool -->
    <SectionCard
      :title="`Pending in-site visits (${pending.length})`"
      icon="pi pi-inbox"
      class="mb-5"
    >
      <p v-if="loading" class="py-4 text-center text-sm text-mute">Loading…</p>
      <template v-else>
        <p v-if="!pending.length" class="mb-2 text-sm text-mute">
          <i class="pi pi-check-circle mr-1 text-success" aria-hidden="true" />
          No visit waiting for an agent — drop one here to un-assign it.
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
              class="flex cursor-grab items-center gap-2 rounded-xl border border-dashed border-amber-300 bg-amber-50 px-3 py-2 text-sm active:cursor-grabbing dark:border-amber-500/40 dark:bg-amber-500/10"
            >
              <i class="pi pi-map-marker text-amber-600 dark:text-amber-400" aria-hidden="true" />
              <span class="font-medium text-ink">{{ element.client ?? '—' }}</span>
              <span class="num text-xs text-mute">due {{ formatDateTime(element.due_at) }}</span>
              <button
                type="button"
                class="text-mute transition-colors hover:text-ink"
                aria-label="Task details"
                @click.stop="showDetails($event, element)"
              >
                <i class="pi pi-info-circle" aria-hidden="true" />
              </button>
            </div>
          </template>
        </draggable>
      </template>
    </SectionCard>

    <!-- Agents × weekdays grid -->
    <SectionCard flush>
      <p v-if="loading" class="py-10 text-center text-sm text-mute">Loading the week…</p>
      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[900px] border-collapse text-sm">
          <thead>
            <tr>
              <th class="sticky left-0 z-10 border-b border-line bg-card px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-mute">
                Agent
              </th>
              <th
                v-for="d in days"
                :key="d.date"
                class="border-b border-l border-line px-2 py-2 text-left text-xs font-semibold uppercase tracking-wide"
                :class="d.isToday ? 'bg-highlight text-primary-700 dark:text-primary-300' : d.isPast ? 'text-mute/60' : 'text-mute'"
              >
                {{ d.label }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="agent in agents" :key="agent.id" class="align-top">
              <th class="sticky left-0 z-10 border-b border-line bg-card px-3 py-2 text-left font-medium text-ink">
                {{ agent.name }}
                <span class="num mt-0.5 block text-xs font-normal text-mute">
                  {{ days.reduce((n, d) => n + cellList(agent.id, d.date).length, 0) }} this week
                </span>
              </th>
              <td
                v-for="d in days"
                :key="d.date"
                class="border-b border-l border-line p-1.5"
                :class="{ 'bg-surface-50 dark:bg-surface-900/40': d.isPast, 'bg-highlight/40': d.isToday }"
              >
                <draggable
                  :list="cells[cellKey(agent.id, d.date)]"
                  item-key="id"
                  v-bind="dragOptions"
                  :move="checkMove"
                  :group="{ name: 'dispatch', put: canReceive(d), pull: true }"
                  class="min-h-[52px] space-y-1"
                  @change="(evt) => onDropToCell(evt, agent.id, d.date)"
                >
                  <template #item="{ element }">
                    <div
                      class="rounded-lg px-2 py-1.5 text-xs"
                      :class="[
                        TYPE_TONES[element.type] ?? 'bg-surface-100 dark:bg-surface-800',
                        element.draggable ? 'cursor-grab active:cursor-grabbing' : 'cursor-default opacity-80',
                        element.is_completed ? 'line-through opacity-60' : '',
                      ]"
                    >
                      <button
                        type="button"
                        class="flex w-full items-center gap-1.5 text-left"
                        @click="showDetails($event, element)"
                      >
                        <i :class="TYPE_ICONS[element.type]" aria-hidden="true" />
                        <span class="min-w-0 flex-1 truncate font-medium">{{ element.client ?? '—' }}</span>
                        <span v-if="element.unit" class="num shrink-0">{{ element.unit }}</span>
                      </button>
                    </div>
                  </template>
                </draggable>
              </td>
            </tr>
            <tr v-if="!agents.length">
              <td colspan="8" class="px-4 py-8 text-center text-sm text-mute">
                No field agents (is_agent roles) found.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </SectionCard>

    <p class="mt-2 text-xs text-mute">
      Drag between agents and days (today onwards), or back to the pending strip to un-assign.
      Faded cards (calls, office visits, completed) are workload context and don't drag.
    </p>

    <!-- Task details -->
    <Popover ref="detailsRef" class="w-80 max-w-[92vw]">
      <div v-if="detailsItem" class="space-y-2 text-sm">
        <p class="flex items-center gap-2 font-semibold text-ink">
          <i :class="TYPE_ICONS[detailsItem.type] ?? 'pi pi-map-marker'" aria-hidden="true" />
          {{ humanize(detailsItem.type ?? 'in_site') }}
          <Tag v-if="detailsItem.is_completed" value="completed" severity="secondary" />
        </p>
        <dl class="space-y-1.5">
          <div class="flex justify-between gap-2">
            <dt class="text-mute">Client</dt>
            <dd class="text-ink">{{ detailsItem.client ?? '—' }}</dd>
          </div>
          <div class="flex justify-between gap-2">
            <dt class="text-mute">When</dt>
            <dd class="num text-ink">{{ formatDateTime(detailsItem.at ?? detailsItem.due_at) }}</dd>
          </div>
          <div v-if="detailsItem.unit" class="flex justify-between gap-2">
            <dt class="text-mute">Unit</dt>
            <dd class="text-ink">{{ detailsItem.unit }}</dd>
          </div>
          <div v-if="detailsItem.location" class="flex justify-between gap-2">
            <dt class="text-mute">Site</dt>
            <dd class="text-ink">{{ detailsItem.location }}</dd>
          </div>
        </dl>
        <div class="flex items-center gap-3 border-t border-line pt-2">
          <RouterLink
            v-if="detailsItem.link"
            :to="detailsItem.link"
            class="inline-flex items-center gap-1.5 text-primary-600 hover:underline dark:text-primary-400"
          >
            <i class="pi pi-folder-open" aria-hidden="true" /> Open project
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
        </div>
      </div>
    </Popover>
  </div>
</template>
