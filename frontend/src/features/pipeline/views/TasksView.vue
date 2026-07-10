<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import BaseMultiSelect from '@/components/base/BaseMultiSelect.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'
import BaseModal from '@/components/base/BaseModal.vue'
import TimeField from '@/components/base/TimeField.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import OfflineStamp from '@/components/ui/OfflineStamp.vue'
import FilterPanel from '@/components/ui/FilterPanel.vue'
import { useTasksStore } from '@/features/pipeline/tasksStore'
import { useAuthStore } from '@/features/settings/store'
import { confirmAction, toastSuccess, toastError } from '@/composables/useConfirm'
import { countActiveFilters, dateInputValue, intlLocale, todayInput } from '@/utils/format'
import { t } from '@/i18n'

const store = useTasksStore()
const auth = useAuthStore()

// Team layer: create tasks FOR other users + see/close the whole team's board.
const canAssign = computed(() => auth.can('tasks.assign'))

const activeFilterCount = computed(() => countActiveFilters(store.filters, ['scope']))

// ── Task categories: the CRM day is not only client follow-up — the board
// also carries content production (TikTok/reels), scheduled publications,
// prospecting, training and admin chores. Icon + tint per category.
const categoryMeta = {
  follow_up: { icon: 'pi pi-phone', chip: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300' },
  prospecting: { icon: 'pi pi-users', chip: 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300' },
  content: { icon: 'pi pi-video', chip: 'bg-pink-100 text-pink-700 dark:bg-pink-500/15 dark:text-pink-300' },
  publication: { icon: 'pi pi-megaphone', chip: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' },
  training: { icon: 'pi pi-book', chip: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' },
  admin: { icon: 'pi pi-file', chip: 'bg-surface-100 text-mute dark:bg-surface-800' },
}
const categoryOptions = computed(() =>
  Object.keys(categoryMeta).map((value) => ({ value, label: t(`tasks.categories.${value}`) })),
)

// Quick templates: one tap prefills the form with the agency's routine work.
const templates = [
  { key: 'followup', icon: 'pi pi-phone', category: 'follow_up', repeat: '' },
  { key: 'tiktok', icon: 'pi pi-video', category: 'content', repeat: '' },
  { key: 'publish', icon: 'pi pi-megaphone', category: 'publication', repeat: 12 },
  { key: 'formation', icon: 'pi pi-book', category: 'training', repeat: '' },
  { key: 'reading', icon: 'pi pi-book', category: 'training', repeat: 168 },
]

const repeatOptions = computed(() =>
  [12, 24, 48, 168].map((h) => ({ value: h, label: repeatLabel(h) })),
)

const emptyForm = () => ({
  title: '',
  description: '',
  category: 'follow_up',
  assigned_to_ids: [], // empty = just me; several users = one task each
  priority: 'normal',
  due_date: '',
  due_time: '',
  repeat_every_hours: '',
})
const form = reactive(emptyForm())

function applyTemplate(tpl) {
  form.title = t(`tasks.tpl.${tpl.key}`)
  form.category = tpl.category
  form.repeat_every_hours = tpl.repeat
}

onMounted(() => store.fetch())
useRefreshable(() => store.fetch()) // pull-to-refresh (APK)

// ── Board grouping: open tasks split into overdue / today / upcoming / no
// due date; done tasks keep their completion report visible below.
const openTasks = computed(() => store.items.filter((t) => t.state === 'open'))
const doneTasks = computed(() => store.items.filter((t) => t.state === 'done'))

function isOverdue(task) {
  return task.state === 'open' && task.due_at && new Date(task.due_at) <= new Date()
}
function isToday(task) {
  return task.due_at && dateInputValue(task.due_at) === todayInput()
}

const openGroups = computed(() => {
  const groups = { overdue: [], today: [], upcoming: [], noDue: [] }
  for (const task of openTasks.value) {
    if (!task.due_at) groups.noDue.push(task)
    else if (isOverdue(task)) groups.overdue.push(task)
    else if (isToday(task)) groups.today.push(task)
    else groups.upcoming.push(task)
  }
  return groups
})
const groupOrder = [
  { key: 'overdue', icon: 'pi pi-exclamation-circle', tone: 'text-danger' },
  { key: 'today', icon: 'pi pi-calendar', tone: 'text-ink' },
  { key: 'upcoming', icon: 'pi pi-clock', tone: 'text-mute' },
  { key: 'noDue', icon: 'pi pi-minus', tone: 'text-mute' },
]

async function quickAdd() {
  if (!form.title.trim()) return
  // Combine the split date + time back into the datetime the API expects; a bare
  // date (no time) defaults to the start of that day.
  const dueAt = form.due_date ? `${form.due_date}T${form.due_time || '00:00'}` : null
  const payload = {
    title: form.title.trim(),
    description: form.description.trim() || null,
    category: form.category,
    priority: form.priority,
    assigned_to_ids: canAssign.value && form.assigned_to_ids.length ? form.assigned_to_ids : null,
    due_at: dueAt,
    repeat_every_hours: form.repeat_every_hours || null,
  }
  try {
    await store.create(payload)
    Object.assign(form, emptyForm())
  } catch {
    /* error surfaced via store.error */
  }
}

// ── Completion report: done is never just a tick — the dialog asks what was
// actually done, how it went, difficulties and time spent.
const completing = ref(null)
const emptyReport = () => ({ outcome: 'full', summary: '', difficulties: '', time_spent_minutes: '' })
const report = reactive(emptyReport())
const reportError = ref('')

function openComplete(task) {
  Object.assign(report, emptyReport())
  reportError.value = ''
  completing.value = task
}

async function submitComplete() {
  if (!report.summary.trim()) {
    reportError.value = t('tasks.summaryRequired')
    return
  }
  try {
    await store.complete(completing.value.id, {
      outcome: report.outcome,
      summary: report.summary.trim(),
      difficulties: report.difficulties.trim() || null,
      time_spent_minutes: Number(report.time_spent_minutes) || null,
    })
    completing.value = null
    toastSuccess(t('tasks.completed'))
  } catch (e) {
    toastError(e.response?.data?.message ?? t('tasks.completeFailed'))
  }
}

async function cancelTask(task) {
  if (
    await confirmAction({
      title: t('tasks.cancelTitle', { title: task.title }),
      text: t('tasks.cancelText'),
      confirmText: t('tasks.cancelConfirm'),
      danger: true,
    })
  ) {
    store.cancel(task.id)
  }
}

const prioritySeverity = { high: 'danger', normal: 'info', low: 'secondary' }
const outcomeSeverity = { full: 'success', partial: 'warn', issues: 'danger' }

function repeatLabel(hours) {
  return hours === 168 ? t('tasks.repeatWeekly') : t('tasks.repeatEveryHours', { n: hours })
}

function formatDue(value) {
  if (!value) return '—'
  return new Date(value).toLocaleString(intlLocale(), {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  })
}
</script>

<template>
  <div>
    <PageHeader :title="$t('nav.tasks')" :subtitle="$t('tasks.subtitle')" />
    <OfflineStamp :at="store.offlineAt" />

    <!-- New task -->
    <SectionCard :title="$t('tasks.newTask')" icon="pi pi-plus-circle" class="mb-5">
      <div class="mb-4 flex flex-wrap items-center gap-2">
        <span class="text-xs font-semibold uppercase tracking-wide text-mute">
          {{ $t('tasks.quickIdeas') }}
        </span>
        <Button
          v-for="tpl in templates"
          :key="tpl.key"
          :label="$t(`tasks.tpl.${tpl.key}`)"
          :icon="tpl.icon"
          size="small"
          outlined
          severity="secondary"
          @click="applyTemplate(tpl)"
        />
      </div>

      <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="quickAdd">
        <BaseInput
          v-model="form.title"
          :label="$t('tasks.newTask')"
          required
          :placeholder="$t('tasks.whatNeedsDoing')"
          class="sm:col-span-2"
        />
        <BaseSelect
          v-model="form.category"
          :label="$t('tasks.category')"
          :clearable="false"
          :options="categoryOptions"
        />
        <BaseSelect
          v-model="form.priority"
          :label="$t('tasks.priority')"
          :clearable="false"
          :options="[
            { value: 'low', label: $t('status.low') },
            { value: 'normal', label: $t('tasks.normal') },
            { value: 'high', label: $t('status.high') },
          ]"
        />
        <BaseMultiSelect
          v-if="canAssign"
          v-model="form.assigned_to_ids"
          :label="$t('pipeline.assignedTo')"
          :placeholder="$t('tasks.me')"
          :options="store.agents.map((a) => ({ value: a.id, label: a.name }))"
        />
        <BaseInput
          v-model="form.due_date"
          :label="$t('pipeline.dueDate')"
          type="date"
          :min="todayInput()"
        />
        <div class="block">
          <span class="mb-1.5 block text-sm font-medium text-ink">
            {{ $t('common.time') }}
          </span>
          <TimeField v-model="form.due_time" :aria-label="$t('common.time')" />
        </div>
        <BaseSelect
          v-model="form.repeat_every_hours"
          :label="$t('tasks.repeat')"
          :placeholder="$t('tasks.repeatNone')"
          :options="repeatOptions"
        />
        <BaseTextarea
          v-model="form.description"
          :label="$t('common.description')"
          :placeholder="$t('tasks.descriptionPlaceholder')"
          :rows="3"
          class="sm:col-span-2 lg:col-span-4"
        />
        <div class="lg:col-span-4">
          <Button type="submit" :label="$t('tasks.addTask')" icon="pi pi-plus" :loading="store.saving" />
        </div>
      </form>
    </SectionCard>

    <SectionCard flush>
      <!-- Filter toolbar -->
      <FilterPanel :active-count="activeFilterCount">
      <div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3 sm:px-5">
        <BaseSelect
          v-if="canAssign"
          v-model="store.filters.scope"
          :aria-label="$t('tasks.scope')"
          class="w-full sm:w-36"
          :clearable="false"
          :options="[
            { value: 'mine', label: $t('tasks.mine') },
            { value: 'team', label: $t('tasks.team') },
          ]"
          @change="store.fetch()"
        />
        <BaseSelect
          v-model="store.filters.state"
          :placeholder="$t('tasks.allStates')"
          :aria-label="$t('common.status')"
          class="w-full sm:w-36"
          :options="[
            { value: 'open', label: $t('status.open') },
            { value: 'done', label: $t('status.done') },
          ]"
          @change="store.fetch()"
        />
        <BaseSelect
          v-model="store.filters.category"
          :placeholder="$t('tasks.allCategories')"
          :aria-label="$t('tasks.category')"
          class="w-full sm:w-44"
          :options="categoryOptions"
          @change="store.fetch()"
        />
        <BaseSelect
          v-model="store.filters.priority"
          :placeholder="$t('tasks.allPriorities')"
          :aria-label="$t('tasks.priority')"
          class="w-full sm:w-40"
          :options="[
            { value: 'high', label: $t('status.high') },
            { value: 'normal', label: $t('tasks.normal') },
            { value: 'low', label: $t('status.low') },
          ]"
          @change="store.fetch()"
        />
        <label class="flex cursor-pointer items-center gap-2 text-sm text-ink">
          <Checkbox v-model="store.filters.overdue" binary @change="store.fetch()" />
          {{ $t('tasks.overdueOnly') }}
        </label>
      </div>
      </FilterPanel>

      <p v-if="store.loading" class="py-8 text-center text-sm text-mute">{{ $t('common.loading') }}</p>

      <div v-else>
        <EmptyState
          v-if="!openTasks.length"
          icon="pi pi-check-circle"
          :title="$t('tasks.nothingOpen')"
        />
        <section v-for="group in groupOrder" :key="group.key">
          <template v-if="openGroups[group.key].length">
            <h2
              class="flex items-center gap-2 border-b border-line px-4 py-2.5 text-xs font-semibold uppercase tracking-wide sm:px-5"
              :class="group.tone"
            >
              <i :class="group.icon" aria-hidden="true" />
              {{ $t(`tasks.groups.${group.key}`) }} ({{ openGroups[group.key].length }})
            </h2>
            <ul class="divide-y divide-line">
              <li
                v-for="task in openGroups[group.key]"
                :key="task.id"
                class="flex items-center gap-3 px-4 py-3 sm:px-5"
              >
                <span
                  class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
                  :class="
                    isOverdue(task)
                      ? 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300'
                      : categoryMeta[task.category]?.chip ?? 'bg-surface-100 text-mute dark:bg-surface-800'
                  "
                >
                  <i
                    :class="categoryMeta[task.category]?.icon ?? 'pi pi-circle'"
                    aria-hidden="true"
                  />
                </span>
                <div class="min-w-0 flex-1">
                  <p class="flex flex-wrap items-center gap-2">
                    <span class="truncate text-sm font-medium text-ink">{{ task.title }}</span>
                    <Tag
                      v-if="task.priority !== 'normal'"
                      :value="$t(`status.${task.priority}`)"
                      :severity="prioritySeverity[task.priority]"
                    />
                    <Tag
                      v-if="task.repeat_every_hours"
                      :value="repeatLabel(task.repeat_every_hours)"
                      severity="secondary"
                      icon="pi pi-replay"
                    />
                  </p>
                  <p v-if="task.description" class="mt-0.5 truncate text-xs text-mute">
                    {{ task.description }}
                  </p>
                  <p class="mt-0.5 text-xs text-mute">
                    <span :class="{ 'font-medium text-danger': isOverdue(task) }">
                      {{ $t('pipeline.due', { date: formatDue(task.due_at) }) }}
                    </span>
                    · {{ $t(`tasks.categories.${task.category}`) }}
                    · {{ task.assigned_to?.name ?? $t('clients.unassigned') }}
                    <span v-if="task.created_by && task.created_by.id !== task.assigned_to?.id">
                      · {{ $t('tasks.byUser', { name: task.created_by.name }) }}
                    </span>
                    <span v-if="task.subject_label"> · {{ task.subject_label }}</span>
                  </p>
                </div>
                <Button
                  :label="$t('status.done')"
                  icon="pi pi-check"
                  size="small"
                  outlined
                  severity="success"
                  @click="openComplete(task)"
                />
                <Button
                  icon="pi pi-ban"
                  text
                  rounded
                  size="small"
                  severity="danger"
                  :aria-label="$t('tasks.cancelConfirm')"
                  @click="cancelTask(task)"
                />
              </li>
            </ul>
          </template>
        </section>

        <section v-if="doneTasks.length">
          <h2
            class="border-y border-line px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-mute sm:px-5"
          >
            {{ $t('status.done') }} ({{ doneTasks.length }})
          </h2>
          <ul class="divide-y divide-line">
            <li v-for="task in doneTasks" :key="task.id" class="px-4 py-2.5 sm:px-5">
              <p class="flex flex-wrap items-center gap-2">
                <i class="pi pi-check-circle text-success" aria-hidden="true" />
                <span class="truncate text-sm text-mute line-through">{{ task.title }}</span>
                <Tag
                  v-if="task.completion_outcome"
                  :value="$t(`tasks.outcomes.${task.completion_outcome}`)"
                  :severity="outcomeSeverity[task.completion_outcome]"
                />
              </p>
              <p v-if="task.completion_summary" class="mt-1 text-xs text-ink">
                {{ task.completion_summary }}
              </p>
              <p v-if="task.completion_difficulties" class="mt-0.5 text-xs text-mute">
                <i class="pi pi-exclamation-triangle" aria-hidden="true" />
                {{ task.completion_difficulties }}
              </p>
              <p class="mt-0.5 text-xs text-mute">
                {{ formatDue(task.completed_at) }}
                <span v-if="task.completed_by"> · {{ task.completed_by.name }}</span>
                <span v-if="task.time_spent_minutes">
                  · {{ $t('tasks.minutesSpent', { n: task.time_spent_minutes }) }}
                </span>
              </p>
            </li>
          </ul>
        </section>
      </div>
    </SectionCard>

    <!-- Completion report -->
    <BaseModal
      v-if="completing"
      :title="$t('tasks.completeTitle', { title: completing.title })"
      @close="completing = null"
    >
      <form class="grid gap-3" @submit.prevent="submitComplete">
        <BaseSelect
          v-model="report.outcome"
          :label="$t('tasks.outcome')"
          required
          :clearable="false"
          :options="[
            { value: 'full', label: $t('tasks.outcomes.full') },
            { value: 'partial', label: $t('tasks.outcomes.partial') },
            { value: 'issues', label: $t('tasks.outcomes.issues') },
          ]"
        />
        <BaseTextarea
          v-model="report.summary"
          :label="$t('tasks.whatDidYouDo')"
          required
          :rows="4"
          :placeholder="$t('tasks.whatDidYouDoPlaceholder')"
          :error="reportError"
        />
        <BaseTextarea
          v-model="report.difficulties"
          :label="$t('tasks.difficulties')"
          :rows="3"
          :placeholder="$t('tasks.difficultiesPlaceholder')"
        />
        <BaseInput
          v-model="report.time_spent_minutes"
          :label="$t('tasks.timeSpent')"
          type="number"
          :min="1"
          :max="10080"
        />
        <div class="flex justify-end gap-2 pt-1">
          <Button
            :label="$t('common.cancel')"
            severity="secondary"
            outlined
            @click="completing = null"
          />
          <Button
            type="submit"
            :label="$t('tasks.submitComplete')"
            icon="pi pi-check"
            severity="success"
            :loading="store.saving"
          />
        </div>
      </form>
    </BaseModal>
  </div>
</template>
