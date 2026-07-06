<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import Button from 'primevue/button'
import { pipelineApi } from '@/features/pipeline/api'

// The "when are you free?" picker on the next-action form: the signed-in agent's
// own workload for the coming week, one cell per day with a capacity meter, so she
// schedules the follow-up onto a lighter day instead of piling onto a full one.
// Tapping a day sets it as the due date (two-way `modelValue`) AND reveals that
// day's schedule below — the type + time of everything already on it, so the
// question "is this a good day?" is answered inline (works on touch, not hover).
// Collapsible via the corner −/+ (once the day is picked, it can fold away).
//
// Dates are UTC throughout — the backend buckets by UTC day (the same reference
// the date <input> stores under and the dispatch board uses), so a clicked cell
// maps to exactly the value a plan is saved with. Times come pre-formatted (UTC)
// from the server, so a date-only plan reads as "All day", never a stray "00:00".
const props = defineProps({
  // The currently chosen due date ('YYYY-MM-DD'), highlighted in the picker.
  modelValue: { type: String, default: '' },
  days: { type: Number, default: 7 },
})
const emit = defineEmits(['update:modelValue'])

// A day reads as "full" at this many items — the meter fills to here and the day
// flags amber (overloaded), the cue to look for a lighter slot.
const FULL_DAY = 5
// How far ahead the week picker may look — a follow-up scheduled further out than
// this is a rarity better handled by the native date input.
const MAX_WEEK = 8

const buckets = ref([]) // [{ date, items: [{ kind, time, client, label }] }]
const loading = ref(true)
const failed = ref(false)
const expanded = ref(true)
// Which week is shown: 0 = the seven days from today, 1 = the next seven, … The
// window rolls from today (not a Mon–Sun calendar week) so every cell shown is
// always schedulable — no dead past days to grey out.
const weekOffset = ref(0)

const todayUtc = () => new Date().toISOString().slice(0, 10)
const addUtcDays = (iso, n) => {
  const d = new Date(iso + 'T00:00:00Z')
  d.setUTCDate(d.getUTCDate() + n)
  return d.toISOString().slice(0, 10)
}

// The first day of the shown window, derived from the offset (deterministic —
// the label never waits on the fetch to settle).
const weekStart = computed(() => addUtcDays(todayUtc(), weekOffset.value * props.days))

const KIND = {
  call: { icon: 'pi pi-phone', label: 'Call' },
  office_visit: { icon: 'pi pi-building', label: 'Office visit' },
  in_site_visit: { icon: 'pi pi-map-marker', label: 'In-site visit' },
  task: { icon: 'pi pi-check-square', label: 'Task' },
}
const kindOf = (k) => KIND[k] ?? { icon: 'pi pi-circle', label: k }

// One cell of derived display state per returned day.
const columns = computed(() => {
  const today = todayUtc()
  return buckets.value.map((b) => {
    const d = new Date(b.date + 'T00:00:00Z')
    const count = b.items.length
    return {
      date: b.date,
      weekday: d.toLocaleDateString(undefined, { weekday: 'short', timeZone: 'UTC' }),
      dayNum: d.toLocaleDateString(undefined, { day: 'numeric', timeZone: 'UTC' }),
      isToday: b.date === today,
      items: b.items,
      count,
      fill: Math.min(count / FULL_DAY, 1), // 0..1 capacity meter
      overloaded: count >= FULL_DAY,
    }
  })
})

const total = computed(() => columns.value.reduce((n, c) => n + c.count, 0))
const selectedCol = computed(() => columns.value.find((c) => c.date === props.modelValue) ?? null)
const dayTitle = (col) => (col.isToday ? 'today' : `${col.weekday} ${col.dayNum}`)

// "This week" / "Next week" read plainer than a date range; beyond that the range
// (e.g. "19–25 Jul") is clearer than "in 3 weeks".
const weekLabel = computed(() => {
  if (weekOffset.value === 0) return 'This week'
  if (weekOffset.value === 1) return 'Next week'
  const start = new Date(weekStart.value + 'T00:00:00Z')
  const end = new Date(addUtcDays(weekStart.value, props.days - 1) + 'T00:00:00Z')
  const sameMonth = start.getUTCMonth() === end.getUTCMonth()
  const fmt = (d, month) =>
    d.toLocaleDateString(undefined, {
      day: 'numeric',
      month: month ? 'short' : undefined,
      timeZone: 'UTC',
    })
  return `${fmt(start, !sameMonth)}–${fmt(end, true)}`
})
const canPrev = computed(() => weekOffset.value > 0) // no scheduling in the past
const canNext = computed(() => weekOffset.value < MAX_WEEK)

async function load() {
  loading.value = true
  failed.value = false
  try {
    // `from` is omitted on the current week so the server anchors on its own "now".
    buckets.value = await pipelineApi.myAgenda({
      days: props.days,
      from: weekOffset.value === 0 ? null : weekStart.value,
    })
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

function shiftWeek(delta) {
  const next = weekOffset.value + delta
  if (next < 0 || next > MAX_WEEK) return
  weekOffset.value = next
  load()
}

onMounted(load)
// A newly-planned action changes the strip; reload when the window size changes.
watch(() => props.days, load)

function select(date) {
  emit('update:modelValue', props.modelValue === date ? '' : date)
}
</script>

<template>
  <div class="overflow-hidden rounded-xl border border-line bg-card">
    <!-- Header: label · (collapsed summary) · collapse -->
    <div class="flex items-center justify-between gap-2 px-3 py-2.5">
      <span
        class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-mute"
      >
        <i class="pi pi-calendar text-[11px]" aria-hidden="true" />
        Your workload
      </span>
      <span class="flex items-center gap-1.5">
        <span v-if="!expanded" class="text-[11px] text-mute">
          {{ total }} planned · {{ weekLabel.toLowerCase() }}
        </span>
        <Button
          :icon="expanded ? 'pi pi-minus' : 'pi pi-plus'"
          text
          rounded
          size="small"
          severity="secondary"
          class="!h-7 !w-7 shrink-0"
          :aria-label="expanded ? 'Collapse your workload' : 'Expand your workload'"
          :aria-expanded="expanded"
          @click="expanded = !expanded"
        />
      </span>
    </div>

    <template v-if="expanded">
      <!-- Week picker: step the seven-day window forward to schedule further out
           (back is capped at this week — no scheduling in the past). Stays put
           while a week loads so it never jumps under the cursor. -->
      <div class="mb-0.5 flex items-center justify-between gap-1 px-2">
        <Button
          icon="pi pi-chevron-left"
          text
          rounded
          size="small"
          severity="secondary"
          class="!h-7 !w-7 shrink-0"
          :disabled="!canPrev"
          aria-label="Previous week"
          @click="shiftWeek(-1)"
        />
        <button
          type="button"
          class="min-w-0 flex-1 truncate rounded-md px-2 py-1 text-center text-xs font-medium text-ink transition-colors"
          :class="
            canPrev
              ? 'cursor-pointer hover:bg-surface-100 dark:hover:bg-surface-800'
              : 'cursor-default'
          "
          :title="canPrev ? 'Back to this week' : null"
          @click="canPrev && shiftWeek(-weekOffset)"
        >
          {{ weekLabel }}
          <span v-if="total" class="font-normal text-mute">· {{ total }}</span>
        </button>
        <Button
          icon="pi pi-chevron-right"
          text
          rounded
          size="small"
          severity="secondary"
          class="!h-7 !w-7 shrink-0"
          :disabled="!canNext"
          aria-label="Next week"
          @click="shiftWeek(1)"
        />
      </div>

      <div v-if="loading" class="px-3 pb-5 pt-3 text-center text-xs text-mute">
        <i class="pi pi-spin pi-spinner" aria-hidden="true" /> Loading your agenda…
      </div>
      <p v-else-if="failed" class="px-3 pb-5 pt-3 text-center text-xs text-mute">
        Couldn't load your agenda.
        <button type="button" class="font-medium text-primary-600 underline" @click="load">
          Retry
        </button>
      </p>

      <template v-else>
        <!-- The week: one selectable cell per day, each with a capacity meter. -->
        <div class="grid grid-cols-7 gap-1 px-2 pb-2">
          <button
            v-for="col in columns"
            :key="col.date"
            type="button"
            class="flex flex-col items-center gap-1.5 rounded-lg border px-0.5 py-2 transition-colors"
            :class="
              modelValue === col.date
                ? 'border-primary bg-primary text-primary-contrast shadow-sm'
                : 'border-transparent hover:bg-surface-100 dark:hover:bg-surface-800'
            "
            :aria-pressed="modelValue === col.date"
            :aria-label="`${col.weekday} ${col.dayNum} — ${col.count === 0 ? 'free' : col.count + ' scheduled'}. Set as due date`"
            @click="select(col.date)"
          >
            <span
              class="text-[10px] font-medium uppercase leading-none tracking-wide"
              :class="
                modelValue === col.date
                  ? 'text-primary-contrast'
                  : col.isToday
                    ? 'text-primary-600 dark:text-primary-400'
                    : 'text-mute'
              "
            >
              {{ col.isToday ? 'Today' : col.weekday }}
            </span>
            <span
              class="text-base font-semibold leading-none"
              :class="modelValue === col.date ? 'text-primary-contrast' : 'text-ink'"
            >
              {{ col.dayNum }}
            </span>

            <!-- Capacity meter — how full the day is, at a glance. -->
            <span
              class="mt-0.5 h-1 w-7 overflow-hidden rounded-full"
              :class="modelValue === col.date ? 'bg-black/15' : 'bg-line'"
            >
              <span
                class="block h-full rounded-full"
                :class="
                  modelValue === col.date
                    ? 'bg-primary-contrast'
                    : col.overloaded
                      ? 'bg-warning'
                      : 'bg-primary'
                "
                :style="{ width: `${col.fill * 100}%` }"
              />
            </span>

            <span
              class="text-[10px] leading-none"
              :class="
                modelValue === col.date
                  ? 'text-primary-contrast'
                  : col.count === 0
                    ? 'text-mute/60'
                    : col.overloaded
                      ? 'font-semibold text-warning'
                      : 'text-mute'
              "
            >
              {{ col.count === 0 ? 'Free' : col.count }}
            </span>
          </button>
        </div>

        <!-- The picked day's schedule: the type + time of everything on it, so the
             agent sees exactly what she'd be scheduling alongside. -->
        <div v-if="selectedCol" class="border-t border-line bg-ground/40 px-3 py-2.5">
          <p v-if="selectedCol.count === 0" class="flex items-center gap-1.5 text-xs text-success">
            <i class="pi pi-check-circle" aria-hidden="true" />
            <span class="capitalize">{{ dayTitle(selectedCol) }}</span> is clear — good pick.
          </p>
          <template v-else>
            <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-mute">
              On <span class="text-ink">{{ dayTitle(selectedCol) }}</span> · {{ selectedCol.count }}
              <span v-if="selectedCol.overloaded" class="ml-1 text-warning">— heavy day</span>
            </p>
            <ul class="space-y-1">
              <li
                v-for="(it, i) in selectedCol.items"
                :key="i"
                class="flex items-center gap-2 text-xs text-ink"
              >
                <i
                  :class="kindOf(it.kind).icon"
                  class="w-4 text-center text-[11px] text-mute"
                  aria-hidden="true"
                />
                <span class="w-14 shrink-0 tabular-nums text-mute">{{ it.time ?? 'All day' }}</span>
                <span class="truncate">
                  {{ kindOf(it.kind).label
                  }}<span v-if="it.client ?? it.label" class="text-mute">
                    · {{ it.client ?? it.label }}</span
                  >
                </span>
              </li>
            </ul>
          </template>
        </div>
      </template>
    </template>
  </div>
</template>
