<script setup>
import { computed, onMounted, reactive } from 'vue'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import TimeField from '@/components/base/TimeField.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useTasksStore } from '@/features/pipeline/tasksStore'
import { confirmAction } from '@/composables/useConfirm'
import { todayInput } from '@/utils/format'

const store = useTasksStore()

const emptyForm = () => ({
  title: '',
  assigned_to: '',
  priority: 'normal',
  due_date: '',
  due_time: '',
})
const form = reactive(emptyForm())

onMounted(() => store.fetch())
useRefreshable(() => store.fetch()) // pull-to-refresh (APK)

// Open tasks first (board top), then done; overdue open tasks flagged in danger.
const openTasks = computed(() => store.items.filter((t) => t.state === 'open'))
const doneTasks = computed(() => store.items.filter((t) => t.state === 'done'))

function isOverdue(task) {
  return task.state === 'open' && task.due_at && new Date(task.due_at) <= new Date()
}

async function quickAdd() {
  if (!form.title.trim()) return
  // Combine the split date + time back into the datetime the API expects; a bare
  // date (no time) defaults to the start of that day.
  const dueAt = form.due_date ? `${form.due_date}T${form.due_time || '00:00'}` : null
  const payload = {
    title: form.title.trim(),
    priority: form.priority,
    assigned_to: form.assigned_to || null,
    due_at: dueAt,
  }
  try {
    await store.create(payload)
    Object.assign(form, emptyForm())
  } catch {
    /* error surfaced via store.error */
  }
}

async function cancelTask(task) {
  if (
    await confirmAction({
      title: `Cancel task "${task.title}"?`,
      text: 'The record is kept.',
      confirmText: 'Cancel task',
      danger: true,
    })
  ) {
    store.cancel(task.id)
  }
}

const prioritySeverity = { high: 'danger', normal: 'info', low: 'secondary' }

function formatDue(value) {
  if (!value) return '—'
  return new Date(value).toLocaleString(undefined, {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  })
}
</script>

<template>
  <div>
    <PageHeader title="Tasks" subtitle="Your to-dos and the team's. Overdue items are flagged." />

    <!-- Quick add -->
    <SectionCard title="Quick add" icon="pi pi-plus-circle" class="mb-5">
      <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6" @submit.prevent="quickAdd">
        <BaseInput
          v-model="form.title"
          label="New task"
          required
          placeholder="What needs doing?"
          class="lg:col-span-2"
        />
        <BaseSelect
          v-model="form.assigned_to"
          label="Assign to"
          placeholder="Me"
          :options="store.agents.map((a) => ({ value: a.id, label: a.name }))"
        />
        <BaseSelect
          v-model="form.priority"
          label="Priority"
          :clearable="false"
          :options="[
            { value: 'low', label: 'Low' },
            { value: 'normal', label: 'Normal' },
            { value: 'high', label: 'High' },
          ]"
        />
        <BaseInput
          v-model="form.due_date"
          label="Due date"
          type="date"
          :min="todayInput()"
        />
        <div class="block">
          <span class="mb-1.5 block text-sm font-medium text-ink">
            Time
          </span>
          <TimeField v-model="form.due_time" aria-label="Due time" />
        </div>
        <div class="lg:col-span-6">
          <Button type="submit" label="Add task" icon="pi pi-plus" :loading="store.saving" />
        </div>
      </form>
    </SectionCard>

    <SectionCard flush>
      <!-- Filter toolbar -->
      <div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3 sm:px-5">
        <BaseSelect
          v-model="store.filters.scope"
          aria-label="Scope"
          class="w-full sm:w-36"
          :clearable="false"
          :options="[
            { value: 'mine', label: 'Mine' },
            { value: 'team', label: 'Team' },
          ]"
          @change="store.fetch()"
        />
        <BaseSelect
          v-model="store.filters.state"
          placeholder="All states"
          aria-label="State"
          class="w-full sm:w-36"
          :options="[
            { value: 'open', label: 'Open' },
            { value: 'done', label: 'Done' },
          ]"
          @change="store.fetch()"
        />
        <BaseSelect
          v-model="store.filters.priority"
          placeholder="All priorities"
          aria-label="Priority"
          class="w-full sm:w-40"
          :options="[
            { value: 'high', label: 'High' },
            { value: 'normal', label: 'Normal' },
            { value: 'low', label: 'Low' },
          ]"
          @change="store.fetch()"
        />
        <label class="flex cursor-pointer items-center gap-2 text-sm text-ink">
          <Checkbox v-model="store.filters.overdue" binary @change="store.fetch()" />
          Overdue only
        </label>
      </div>

      <p v-if="store.loading" class="py-8 text-center text-sm text-mute">Loading…</p>

      <div v-else>
        <section>
          <h2
            class="border-b border-line px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-mute sm:px-5"
          >
            Open ({{ openTasks.length }})
          </h2>
          <EmptyState
            v-if="!openTasks.length"
            icon="pi pi-check-circle"
            title="Nothing open. Nice."
          />
          <ul v-else class="divide-y divide-line">
            <li
              v-for="t in openTasks"
              :key="t.id"
              class="flex items-center gap-3 px-4 py-3 sm:px-5"
            >
              <span
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
                :class="
                  isOverdue(t)
                    ? 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300'
                    : 'bg-surface-100 text-mute dark:bg-surface-800'
                "
              >
                <i
                  :class="isOverdue(t) ? 'pi pi-exclamation-circle' : 'pi pi-circle'"
                  aria-hidden="true"
                />
              </span>
              <div class="min-w-0 flex-1">
                <p class="flex flex-wrap items-center gap-2">
                  <span class="truncate text-sm font-medium text-ink">{{ t.title }}</span>
                  <Tag
                    v-if="t.priority !== 'normal'"
                    :value="t.priority"
                    :severity="prioritySeverity[t.priority]"
                  />
                </p>
                <p class="mt-0.5 text-xs text-mute">
                  <span :class="{ 'font-medium text-danger': isOverdue(t) }">
                    Due {{ formatDue(t.due_at) }}
                  </span>
                  · {{ t.assigned_to?.name ?? 'Unassigned' }}
                  <span v-if="t.subject_label"> · {{ t.subject_label }}</span>
                </p>
              </div>
              <Button
                label="Done"
                icon="pi pi-check"
                size="small"
                outlined
                severity="success"
                @click="store.complete(t.id)"
              />
              <Button
                icon="pi pi-ban"
                text
                rounded
                size="small"
                severity="danger"
                aria-label="Cancel task"
                @click="cancelTask(t)"
              />
            </li>
          </ul>
        </section>

        <section v-if="doneTasks.length">
          <h2
            class="border-y border-line px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-mute sm:px-5"
          >
            Done ({{ doneTasks.length }})
          </h2>
          <ul class="divide-y divide-line">
            <li
              v-for="t in doneTasks"
              :key="t.id"
              class="flex items-center gap-3 px-4 py-2.5 sm:px-5"
            >
              <i class="pi pi-check-circle text-success" aria-hidden="true" />
              <p class="truncate text-sm text-mute line-through">{{ t.title }}</p>
            </li>
          </ul>
        </section>
      </div>
    </SectionCard>
  </div>
</template>
