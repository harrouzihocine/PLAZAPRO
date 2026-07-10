<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import Button from 'primevue/button'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import BaseModal from '@/components/base/BaseModal.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'
import TimeField from '@/components/base/TimeField.vue'
import { pipelineApi } from '@/features/pipeline/api'
import { useAuthStore } from '@/features/settings/store'
import { confirmAction, toastError, toastSuccess } from '@/composables/useConfirm'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { addDays, formatDate, intlLocale, todayInput } from '@/utils/format'
import { t } from '@/i18n'

// The Office Visits Program: a week of office visits as a day × hour grid
// (empty slots are as informative as full ones), fed by GET /office-program.
// Two audiences: oversight.office_program holders read the grid to pick a free
// slot — the server masks colleagues' visits down to agent + "booked", so a
// masked chip has no client and no link; visits.dispatch holders see full
// names plus the approval strip on top — every beyond-window office-visit plan
// waiting for a verdict (approve / deny with a reason / reschedule with their
// own date). The grid works in Algerian wall-clock dates, same rules as the
// dispatch board: pure Y-m-d string arithmetic, never toISOString on "now".

const auth = useAuthStore()
const canDecide = computed(() => auth.can('visits.dispatch'))

const loading = ref(true)
const weekStart = ref(null) // 'YYYY-MM-DD' (Monday)
const windowDays = ref(1)
const visits = ref([])
const pending = ref([])
const deciding = ref(false)

const today = todayInput()

async function load(week = weekStart.value) {
  loading.value = true
  try {
    const data = await pipelineApi.officeProgram(week)
    weekStart.value = data.week_start
    windowDays.value = data.window_days
    visits.value = data.visits
    pending.value = data.pending_approvals
  } catch (e) {
    toastError(e.response?.data?.message ?? t('program.loadFailed'))
  } finally {
    loading.value = false
  }
}
onMounted(() => load(null))
useRefreshable(() => load()) // pull-to-refresh + reconnect self-heal

function shiftWeek(deltaDays) {
  load(addDays(weekStart.value, deltaDays))
}

const days = computed(() => {
  if (!weekStart.value) return []
  return Array.from({ length: 7 }, (_, i) => {
    const date = addDays(weekStart.value, i)
    return {
      date,
      isToday: date === today,
      label: new Date(date + 'T00:00:00').toLocaleDateString(intlLocale(), {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
      }),
    }
  })
})

// The hour rows: every hour between the earliest and latest visit of the week,
// stretched to at least the 08–17 office day so free slots stay visible. A
// bottom "no time" lane collects day-only plans (the hour isn't known yet).
const hours = computed(() => {
  const hs = visits.value.filter((v) => v.time).map((v) => Number(v.time.slice(0, 2)))
  const from = Math.min(8, ...(hs.length ? hs : [8]))
  const to = Math.max(17, ...(hs.length ? hs : [17]))
  return Array.from({ length: to - from + 1 }, (_, i) => from + i)
})
const hasTimeless = computed(() => visits.value.some((v) => !v.time))

// One O(visits) bucketing per load instead of a filter pass per cell — the
// template asks for 7 days × ~11 rows on every render.
const cellMap = computed(() => {
  const map = new Map()
  for (const v of visits.value) {
    const key = `${v.day}|${v.time ? Number(v.time.slice(0, 2)) : 'none'}`
    if (!map.has(key)) map.set(key, [])
    map.get(key).push(v)
  }
  return map
})
const cellVisits = (day, hour) => cellMap.value.get(`${day}|${hour ?? 'none'}`) ?? []

const hh = (h) => String(h).padStart(2, '0') + ':00'

function visitLink(v) {
  if (!v.client_id) return null
  return v.project_id
    ? { name: 'clients.project', params: { id: v.client_id, projectId: v.project_id } }
    : { name: 'clients.file', params: { id: v.client_id } }
}

const chipClass = {
  scheduled:
    'border-primary-200 bg-primary-50 dark:border-primary-500/30 dark:bg-primary-500/10',
  awaiting_approval:
    'border-dashed border-amber-300 bg-amber-50 dark:border-amber-500/40 dark:bg-amber-500/10',
  done: 'border-emerald-300 bg-emerald-50 opacity-80 dark:border-emerald-500/40 dark:bg-emerald-500/10',
}

/* ---- The approval verdicts (visits.dispatch only) ---------------------- */

async function decide(row, payload, successText) {
  deciding.value = true
  try {
    await pipelineApi.decideOfficeVisitApproval(row.id, payload)
    toastSuccess(successText)
    denyFor.value = null
    rescheduleFor.value = null
    await load()
  } catch (e) {
    toastError(e.response?.data?.message ?? t('program.decisionFailed'))
  } finally {
    deciding.value = false
  }
}

async function approve(row) {
  const ok = await confirmAction({
    title: t('program.approveConfirm', { date: formatDate(row.due_at) }),
    text: row.client ?? '',
    confirmText: t('program.approve'),
  })
  if (ok) await decide(row, { decision: 'approve' }, t('program.approved'))
}

// Deny needs a written reason — it rides the agent's notification.
const denyFor = ref(null)
const denyReason = ref('')
function openDeny(row) {
  denyReason.value = ''
  denyFor.value = row
}
async function submitDeny() {
  if (!denyReason.value.trim()) return
  await decide(denyFor.value, { decision: 'deny', reason: denyReason.value.trim() }, t('program.denied'))
}

// Reschedule = the manager answers with their own date (+ optional hour).
const rescheduleFor = ref(null)
const reschedule = ref({ due_date: '', due_time: '' })
function openReschedule(row) {
  reschedule.value = { due_date: row.day ?? today, due_time: row.time ?? '' }
  rescheduleFor.value = row
}
async function submitReschedule() {
  if (!reschedule.value.due_date) return
  await decide(
    rescheduleFor.value,
    {
      decision: 'reschedule',
      due_date: reschedule.value.due_date,
      due_time: reschedule.value.due_time || null,
    },
    t('program.rescheduled'),
  )
}

const inputClass =
  'w-full rounded-md border border-line bg-card px-3 py-2 min-h-[42px] text-sm text-ink outline-none transition-colors focus:border-primary'
</script>

<template>
  <div>
    <PageHeader :title="$t('program.title')" :subtitle="$t('program.subtitle')" />

    <!-- Plans waiting for a verdict — all weeks, oldest wanted date first.
         Management data: dispatchers only (the server ships [] to others). -->
    <SectionCard
      v-if="canDecide && (pending.length || !loading)"
      :title="$t('program.pendingTitle')"
      icon="pi pi-shield"
      class="mb-4"
    >
      <p v-if="!pending.length" class="text-sm text-mute">{{ $t('program.pendingEmpty') }}</p>
      <ul v-else class="divide-y divide-line">
        <li
          v-for="row in pending"
          :key="row.id"
          class="flex flex-wrap items-center gap-x-4 gap-y-2 py-2.5 text-sm"
        >
          <RouterLink
            v-if="visitLink(row)"
            :to="visitLink(row)"
            class="font-medium text-ink hover:underline"
          >
            {{ row.client ?? '—' }}
          </RouterLink>
          <span class="text-mute">
            {{ $t('program.wantedFor') }}
            <span class="font-medium text-ink">
              {{ formatDate(row.due_at) }}<template v-if="row.time">, {{ row.time }}</template>
            </span>
          </span>
          <span v-if="row.requested_by" class="text-xs text-mute">
            {{ $t('program.requestedBy') }} {{ row.requested_by }}
          </span>
          <span v-if="row.assigned_to" class="text-xs text-mute">
            {{ $t('program.agent') }}: {{ row.assigned_to }}
          </span>
          <span v-if="canDecide" class="ms-auto flex items-center gap-1.5">
            <Button
              size="small"
              icon="pi pi-check"
              :label="$t('program.approve')"
              :disabled="deciding"
              @click="approve(row)"
            />
            <Button
              size="small"
              icon="pi pi-calendar"
              severity="secondary"
              outlined
              :label="$t('program.reschedule')"
              :disabled="deciding"
              @click="openReschedule(row)"
            />
            <Button
              size="small"
              icon="pi pi-times"
              severity="danger"
              outlined
              :label="$t('program.deny')"
              :disabled="deciding"
              @click="openDeny(row)"
            />
          </span>
        </li>
      </ul>
    </SectionCard>

    <!-- Week navigation + legend -->
    <div class="mb-3 flex flex-wrap items-center gap-2">
      <Button
        icon="pi pi-chevron-left"
        severity="secondary"
        outlined
        size="small"
        :aria-label="$t('program.prevWeek')"
        :disabled="loading || !weekStart"
        @click="shiftWeek(-7)"
      />
      <span class="text-sm font-medium text-ink">
        {{ $t('program.weekOf', { date: weekStart ? formatDate(weekStart) : '…' }) }}
      </span>
      <Button
        icon="pi pi-chevron-right"
        severity="secondary"
        outlined
        size="small"
        :aria-label="$t('program.nextWeek')"
        :disabled="loading || !weekStart"
        @click="shiftWeek(7)"
      />
      <Button
        :label="$t('program.thisWeek')"
        severity="secondary"
        text
        size="small"
        :disabled="loading"
        @click="load(null)"
      />
      <span class="ms-auto flex flex-wrap items-center gap-3 text-xs text-mute">
        <span class="flex items-center gap-1.5">
          <span class="inline-block h-2.5 w-2.5 rounded-full bg-primary-400" aria-hidden="true" />
          {{ $t('program.legendScheduled') }}
        </span>
        <span class="flex items-center gap-1.5">
          <span class="inline-block h-2.5 w-2.5 rounded-full bg-amber-400" aria-hidden="true" />
          {{ $t('program.legendAwaiting') }}
        </span>
        <span class="flex items-center gap-1.5">
          <span class="inline-block h-2.5 w-2.5 rounded-full bg-emerald-400" aria-hidden="true" />
          {{ $t('program.legendDone') }}
        </span>
      </span>
    </div>

    <p v-if="loading" class="py-6 text-center text-sm text-mute">{{ $t('common.loading') }}</p>

    <!-- The week grid: one column per day, one row per hour + a "no time" lane. -->
    <div v-else class="overflow-x-auto rounded-xl border border-line bg-card shadow-card">
      <table class="w-full min-w-[860px] border-collapse text-sm">
        <thead>
          <tr>
            <th class="w-16 border-b border-line p-2" aria-hidden="true"></th>
            <th
              v-for="d in days"
              :key="d.date"
              class="border-b border-s border-line p-2 text-center font-semibold"
              :class="d.isToday ? 'bg-highlight text-ink' : 'text-mute'"
            >
              {{ d.label }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="h in hours" :key="h" class="align-top">
            <th class="border-b border-line p-2 text-end text-xs font-medium text-mute num">
              {{ hh(h) }}
            </th>
            <td
              v-for="d in days"
              :key="d.date"
              class="min-w-[110px] border-b border-s border-line p-1.5"
              :class="d.isToday ? 'bg-highlight/40' : ''"
            >
              <component
                :is="visitLink(v) ? RouterLink : 'div'"
                v-for="v in cellVisits(d.date, h)"
                :key="v.id"
                :to="visitLink(v) ?? undefined"
                class="mb-1 block rounded-lg border px-2 py-1.5 last:mb-0"
                :class="chipClass[v.state]"
              >
                <span class="flex items-center gap-1 text-xs font-semibold text-ink num">
                  <i
                    v-if="v.state === 'awaiting_approval'"
                    class="pi pi-clock text-[10px] text-amber-600 dark:text-amber-400"
                    aria-hidden="true"
                  />
                  <i
                    v-else-if="v.state === 'done'"
                    class="pi pi-check text-[10px] text-emerald-600 dark:text-emerald-400"
                    aria-hidden="true"
                  />
                  {{ v.time }}
                </span>
                <span class="block truncate text-xs" :class="v.masked ? 'italic text-mute' : 'text-ink'">
                  {{ v.masked ? $t('program.booked') : (v.client ?? '—') }}
                </span>
                <span v-if="v.agent" class="block truncate text-[11px] text-mute">{{ v.agent }}</span>
              </component>
            </td>
          </tr>
          <tr v-if="hasTimeless" class="align-top">
            <th class="p-2 text-end text-xs font-medium text-mute">{{ $t('program.noTime') }}</th>
            <td
              v-for="d in days"
              :key="d.date"
              class="min-w-[110px] border-s border-line p-1.5"
              :class="d.isToday ? 'bg-highlight/40' : ''"
            >
              <component
                :is="visitLink(v) ? RouterLink : 'div'"
                v-for="v in cellVisits(d.date, null)"
                :key="v.id"
                :to="visitLink(v) ?? undefined"
                class="mb-1 block rounded-lg border px-2 py-1.5 last:mb-0"
                :class="chipClass[v.state]"
              >
                <span
                  class="block truncate text-xs font-medium"
                  :class="v.masked ? 'italic text-mute' : 'text-ink'"
                >
                  {{ v.masked ? $t('program.booked') : (v.client ?? '—') }}
                </span>
                <span v-if="v.agent" class="block truncate text-[11px] text-mute">{{ v.agent }}</span>
              </component>
            </td>
          </tr>
          <tr v-if="!visits.length">
            <td :colspan="days.length + 1" class="p-6 text-center text-sm text-mute">
              {{ $t('program.empty') }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Deny: the reason is required — it rides the agent's notification. -->
    <BaseModal v-if="denyFor" :title="$t('program.denyTitle')" size="max-w-md" @close="denyFor = null">
      <form class="space-y-3" @submit.prevent="submitDeny">
        <p class="text-sm text-mute">
          {{ denyFor.client ?? '—' }} · {{ formatDate(denyFor.due_at)
          }}<template v-if="denyFor.time">, {{ denyFor.time }}</template>
        </p>
        <BaseTextarea v-model="denyReason" :label="$t('program.denyReason')" required :rows="3" />
        <div class="flex justify-end gap-2">
          <Button
            type="button"
            :label="$t('common.cancel')"
            severity="secondary"
            outlined
            size="small"
            @click="denyFor = null"
          />
          <Button
            type="submit"
            :label="$t('program.deny')"
            severity="danger"
            size="small"
            :loading="deciding"
            :disabled="!denyReason.trim()"
          />
        </div>
      </form>
    </BaseModal>

    <!-- Reschedule: the manager's own date (+ optional hour). -->
    <BaseModal
      v-if="rescheduleFor"
      :title="$t('program.rescheduleTitle')"
      size="max-w-md"
      @close="rescheduleFor = null"
    >
      <form class="space-y-3" @submit.prevent="submitReschedule">
        <p class="text-sm text-mute">
          {{ rescheduleFor.client ?? '—' }} · {{ $t('program.wantedFor') }}
          {{ formatDate(rescheduleFor.due_at) }}
        </p>
        <label class="block">
          <span class="mb-1.5 block text-sm font-medium text-ink"
            >{{ $t('pipeline.dueDate') }}<span class="text-danger" aria-hidden="true"> *</span></span
          >
          <input v-model="reschedule.due_date" type="date" :min="today" :class="inputClass" />
        </label>
        <div class="block">
          <span class="mb-1.5 block text-sm font-medium text-ink">{{ $t('common.time') }}</span>
          <TimeField v-model="reschedule.due_time" :aria-label="$t('common.time')" />
        </div>
        <div class="flex justify-end gap-2">
          <Button
            type="button"
            :label="$t('common.cancel')"
            severity="secondary"
            outlined
            size="small"
            @click="rescheduleFor = null"
          />
          <Button
            type="submit"
            :label="$t('program.reschedule')"
            size="small"
            :loading="deciding"
            :disabled="!reschedule.due_date"
          />
        </div>
      </form>
    </BaseModal>
  </div>
</template>
