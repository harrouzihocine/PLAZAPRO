<script setup>
import { ref } from 'vue'
import Button from 'primevue/button'
import StatusTag from '@/components/ui/StatusTag.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useDynamicList } from '@/composables/useDynamicList'
import { googleMapsUrl } from '@/features/inventory/googleMaps'
import { copyToClipboard } from '@/composables/useClipboard'
import { formatDateTime, humanize, unitLine } from '@/utils/format'

// The presentational interaction timeline: one expandable card per entry
// (call / visit / next action), newest first, with edited logs keeping their
// cancelled previous versions nested underneath — history is never hidden.
//
// This is pure display, shared by the project TimelinePanel and the unit page's
// project-logs tab so both read identically. Per-entry affordances (e.g. the
// visit "Complete" button) are injected by the parent through the #actions slot;
// this component owns nothing but the expand/collapse state.
defineProps({
  // Prepared entries: { kind: 'call'|'visit'|'action', at, loggedAt, data }.
  entries: { type: Array, required: true },
  emptyTitle: { type: String, default: 'Nothing here yet' },
  emptyBody: { type: String, default: 'The story starts with the first logged call.' },
})

const { items: officeChecklist } = useDynamicList('office_visit_checklist')
const { items: callTopics } = useDynamicList('call_topics')
const { items: objectionReasons } = useDynamicList('objection_reasons')

// "Discussed" chips are stored as ids — resolve them to their labels for display.
const topicLabels = (ids) =>
  (ids ?? []).map((id) => callTopics.value.find((t) => t.id === id)?.label ?? `#${id}`)
const checklistLabels = (ids) =>
  (ids ?? []).map((id) => officeChecklist.value.find((c) => c.id === id)?.label ?? `#${id}`)
const objectionLabels = (ids) =>
  (ids ?? []).map((id) => objectionReasons.value.find((o) => o.id === id)?.label ?? `#${id}`)

const mapsUrl = (visit) => googleMapsUrl(visit.unit?.location ?? {})

const isCancelled = (row) => row.status === 'cancelled'

// The card headline per log kind (the direction icon for calls is added inline).
function entryTitle(e) {
  if (e.kind === 'call') return e.data.direction === 'in' ? 'Incoming call' : 'Outgoing call'
  if (e.kind === 'action') return `Planned ${humanize(e.data.type)}`
  return `${humanize(e.data.type)} visit`
}

// The at-a-glance facts shown as spaced chips under the headline (collapsed) —
// each is its own pill instead of one long "·"-separated line.
function entryChips(e) {
  const chips = [{ icon: 'pi pi-calendar', text: formatDateTime(e.at) }]
  // In-site logs state when the visit ACTUALLY happened — worth a glance chip
  // next to the scheduled slot (the detail view compares it to the log time).
  if (e.kind === 'visit' && e.data.visited_at) {
    chips.push({ icon: 'pi pi-map-marker', text: `Visited ${formatDateTime(e.data.visited_at)}` })
  }
  if (e.loggedAt) chips.push({ icon: 'pi pi-clock', text: `Logged ${formatDateTime(e.loggedAt)}` })
  if (e.kind === 'visit' && e.data.unit) {
    chips.push({
      icon: 'pi pi-home',
      text: e.data.unit.property_type
        ? `${e.data.unit.reference} · ${e.data.unit.property_type}`
        : e.data.unit.reference,
    })
  }
  const who = e.data.agent?.name ?? e.data.assigned_to?.name
  if (who) chips.push({ icon: 'pi pi-user', text: who })
  if (e.data.outcome) chips.push({ icon: 'pi pi-flag', text: e.data.outcome.label })
  return chips
}

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
</script>

<template>
  <EmptyState v-if="!entries.length" icon="pi pi-phone" :title="emptyTitle" :body="emptyBody" />
  <ol v-else class="mt-4 space-y-4">
    <li v-for="(e, i) in entries" :key="keyOf(e)" class="relative flex gap-3.5">
      <!-- Icon rail: the dot + the connector that threads the whole story. -->
      <div class="flex flex-col items-center">
        <span
          class="z-10 flex h-9 w-9 shrink-0 items-center justify-center rounded-full ring-4 ring-card"
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
        <span v-if="i < entries.length - 1" class="mt-1.5 w-px flex-1 bg-line" aria-hidden="true" />
      </div>

      <!-- The log itself is a card — one clear block per interaction. -->
      <div
        class="min-w-0 flex-1 rounded-xl border border-line bg-card p-4 shadow-card transition-colors"
        :class="{ 'opacity-70': isCancelled(e.data) }"
      >
        <!-- Header: headline + status on the left, actions on the right. -->
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
            <h4
              class="text-sm font-semibold text-ink"
              :class="{ 'text-mute line-through': isCancelled(e.data) }"
            >
              {{ entryTitle(e) }}
            </h4>
            <StatusTag v-if="isCancelled(e.data)" value="cancelled" />
            <StatusTag
              v-else-if="e.kind === 'visit'"
              :value="e.data.is_completed ? 'completed' : 'scheduled'"
            />
            <StatusTag v-else-if="e.kind === 'action'" :value="e.data.state" />
            <span
              v-if="e.data.edited"
              class="rounded-full bg-surface-100 px-2 py-0.5 text-[11px] italic text-mute dark:bg-surface-800"
              :title="e.data.edit_reason"
            >
              edited
            </span>
          </div>
          <span class="flex shrink-0 items-center gap-1">
            <slot name="actions" :entry="e" />
            <Button
              :label="isExpanded(e) ? 'Less' : 'Details'"
              :icon="isExpanded(e) ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"
              icon-pos="right"
              text
              size="small"
              severity="secondary"
              :aria-label="isExpanded(e) ? 'Collapse details' : 'Expand details'"
              @click="toggle(e)"
            />
          </span>
        </div>

        <!-- At-a-glance facts, each its own chip (no cramped "·" run). -->
        <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
          <span
            v-for="(chip, ci) in entryChips(e)"
            :key="ci"
            class="inline-flex items-center gap-1.5 rounded-md bg-surface-50 px-2 py-1 text-xs text-mute dark:bg-surface-800/60"
          >
            <i :class="chip.icon" class="text-[10px]" aria-hidden="true" />
            {{ chip.text }}
          </span>
        </div>

        <p
          v-if="e.data.notes && !isExpanded(e)"
          class="mt-2.5 line-clamp-2 whitespace-pre-line text-sm text-mute"
        >
          {{ e.data.notes }}
        </p>

        <!-- Expanded: the full detail of the log -->
        <div v-if="isExpanded(e)" class="mt-3 space-y-4 border-t border-line pt-3 text-sm">
          <!-- Facts as stacked label/value cells — room to breathe. -->
          <dl class="grid grid-cols-2 gap-x-4 gap-y-3.5 sm:grid-cols-3">
            <template v-if="e.kind === 'call'">
              <div>
                <dt class="text-[11px] font-medium uppercase tracking-wide text-mute">Direction</dt>
                <dd class="mt-0.5 text-ink">{{ e.data.direction === 'in' ? 'Incoming' : 'Outgoing' }}</dd>
              </div>
              <div>
                <dt class="text-[11px] font-medium uppercase tracking-wide text-mute">Called at</dt>
                <dd class="mt-0.5 text-ink">{{ formatDateTime(e.data.called_at) }}</dd>
              </div>
            </template>

            <template v-else-if="e.kind === 'visit'">
              <div>
                <dt class="text-[11px] font-medium uppercase tracking-wide text-mute">Scheduled</dt>
                <dd class="mt-0.5 text-ink">{{ formatDateTime(e.data.scheduled_at) }}</dd>
              </div>
              <!-- When the visit actually happened (agent-stated) vs when its
                   log was filled — the gap is the oversight signal. -->
              <div v-if="e.data.visited_at">
                <dt class="text-[11px] font-medium uppercase tracking-wide text-mute">Visited</dt>
                <dd class="mt-0.5 text-ink">{{ formatDateTime(e.data.visited_at) }}</dd>
              </div>
              <div>
                <dt class="text-[11px] font-medium uppercase tracking-wide text-mute">Log filled</dt>
                <dd class="mt-0.5 text-ink">{{ e.data.completed_at ? formatDateTime(e.data.completed_at) : '—' }}</dd>
              </div>
            </template>

            <template v-else>
              <div>
                <dt class="text-[11px] font-medium uppercase tracking-wide text-mute">Due</dt>
                <dd class="mt-0.5 text-ink">{{ formatDateTime(e.data.due_at) }}</dd>
              </div>
              <div>
                <dt class="text-[11px] font-medium uppercase tracking-wide text-mute">Completed</dt>
                <dd class="mt-0.5 text-ink">{{ e.data.completed_at ? formatDateTime(e.data.completed_at) : '—' }}</dd>
              </div>
            </template>

            <div v-if="e.data.agent || e.data.assigned_to">
              <dt class="text-[11px] font-medium uppercase tracking-wide text-mute">
                {{ e.kind === 'action' ? 'Assigned to' : 'Agent' }}
              </dt>
              <dd class="mt-0.5 text-ink">{{ e.data.agent?.name ?? e.data.assigned_to?.name }}</dd>
            </div>
            <div v-if="e.data.outcome">
              <dt class="text-[11px] font-medium uppercase tracking-wide text-mute">Outcome</dt>
              <dd class="mt-0.5 text-ink">{{ e.data.outcome.label }}</dd>
            </div>
            <div v-if="e.data.created_at">
              <dt class="text-[11px] font-medium uppercase tracking-wide text-mute">Logged</dt>
              <dd class="mt-0.5 text-ink">{{ formatDateTime(e.data.created_at) }}</dd>
            </div>
          </dl>

          <!-- Property card (visits) — the full unit line on its own row. -->
          <div v-if="e.kind === 'visit' && e.data.unit" class="rounded-lg bg-surface-50 p-3 dark:bg-surface-800/50">
            <p class="text-[11px] font-medium uppercase tracking-wide text-mute">Property</p>
            <p class="mt-1 font-medium text-ink">
              {{ unitLine(e.data.unit) }}
              <template v-if="e.data.unit.location?.name"> — {{ e.data.unit.location.name }}</template>
            </p>
            <div v-if="e.data.type === 'in_site' && mapsUrl(e.data)" class="mt-2 flex items-center gap-3">
              <a
                :href="mapsUrl(e.data)"
                target="_blank"
                rel="noopener"
                class="inline-flex items-center gap-1.5 text-primary-600 hover:underline dark:text-primary-400"
              >
                <i class="pi pi-map" aria-hidden="true" /> Open the site in Google Maps
              </a>
              <button
                type="button"
                title="Copy Maps link"
                aria-label="Copy Maps link"
                class="inline-flex items-center text-primary-600 hover:underline dark:text-primary-400"
                @click="copyToClipboard(mapsUrl(e.data), 'Maps link copied')"
              >
                <i class="pi pi-copy" aria-hidden="true" />
              </button>
            </div>
          </div>

          <!-- Discussed (calls) / Checklist (visits) as chips. -->
          <div v-if="e.kind === 'call' && e.data.topics?.length">
            <p class="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-mute">Discussed</p>
            <div class="flex flex-wrap gap-1.5">
              <span
                v-for="label in topicLabels(e.data.topics)"
                :key="label"
                class="rounded-full bg-surface-100 px-2.5 py-1 text-xs text-ink dark:bg-surface-700"
              >
                {{ label }}
              </span>
            </div>
          </div>
          <div v-if="e.kind === 'visit' && e.data.checklist?.length">
            <p class="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-mute">Checklist</p>
            <div class="flex flex-wrap gap-1.5">
              <span
                v-for="label in checklistLabels(e.data.checklist)"
                :key="label"
                class="rounded-full bg-surface-100 px-2.5 py-1 text-xs text-ink dark:bg-surface-700"
              >
                {{ label }}
              </span>
            </div>
          </div>

          <!-- Objections / concerns (calls + visits) — the "why not" signals. -->
          <div v-if="e.data.objections?.length">
            <p class="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-mute">
              Objections
            </p>
            <div class="flex flex-wrap gap-1.5">
              <span
                v-for="label in objectionLabels(e.data.objections)"
                :key="label"
                class="rounded-full bg-warning/10 px-2.5 py-1 text-xs text-ink"
              >
                {{ label }}
              </span>
            </div>
          </div>

          <!-- Notes get their own quoted block — the heart of the log. -->
          <div v-if="e.data.notes">
            <p class="mb-1 text-[11px] font-medium uppercase tracking-wide text-mute">Notes</p>
            <p class="whitespace-pre-line rounded-lg border-l-2 border-primary-300 bg-surface-50 px-3 py-2 text-ink dark:border-primary-500/40 dark:bg-surface-800/50">
              {{ e.data.notes }}
            </p>
          </div>

          <div
            v-if="isCancelled(e.data) && e.data.cancellation_reason"
            class="rounded-lg bg-red-50 px-3 py-2 dark:bg-red-500/10"
          >
            <p class="text-[11px] font-medium uppercase tracking-wide text-danger">Cancelled because</p>
            <p class="mt-0.5 font-medium text-danger">{{ e.data.cancellation_reason }}</p>
          </div>

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
</template>
