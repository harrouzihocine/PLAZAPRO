<script setup>
import { computed, onMounted, reactive } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import { useTasksStore } from '@/features/pipeline/tasksStore'
import { confirmAction } from '@/composables/useConfirm'

const store = useTasksStore()

const selectClass =
  'w-full rounded-token border border-border bg-bg px-3 py-2 min-h-[44px] text-ink outline-none focus:border-primary'

const emptyForm = () => ({ title: '', assigned_to: '', priority: 'normal', due_at: '' })
const form = reactive(emptyForm())

onMounted(() => store.fetch())

// Open tasks first (board top), then done; overdue open tasks flagged in danger.
const openTasks = computed(() => store.items.filter((t) => t.state === 'open'))
const doneTasks = computed(() => store.items.filter((t) => t.state === 'done'))

function isOverdue(task) {
  return task.state === 'open' && task.due_at && new Date(task.due_at) <= new Date()
}

async function quickAdd() {
  if (!form.title.trim()) return
  const payload = {
    title: form.title.trim(),
    priority: form.priority,
    assigned_to: form.assigned_to || null,
    due_at: form.due_at || null,
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

const priorityClass = (p) =>
  ({
    high: 'text-danger',
    low: 'opacity-70',
    normal: '',
  })[p] ?? ''

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
  <div class="space-y-4">
    <div>
      <h1 class="text-xl font-semibold">Tasks</h1>
      <p class="opacity-70">Your to-dos and the team's. Overdue items are flagged.</p>
    </div>


    <!-- Quick add -->
    <BaseCard>
      <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="quickAdd">
        <label class="block lg:col-span-2">
          <span class="mb-1 block text-sm">New task</span>
          <BaseInput v-model="form.title" placeholder="What needs doing?" />
        </label>
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
          :options="[{ value: 'low', label: 'Low' }, { value: 'normal', label: 'Normal' }, { value: 'high', label: 'High' }]"
        />
        <label class="block">
          <span class="mb-1 block text-sm">Due</span>
          <input v-model="form.due_at" type="datetime-local" :class="selectClass" aria-label="Due" />
        </label>
        <div class="lg:col-span-5">
          <BaseButton type="submit" :disabled="store.saving">Add task</BaseButton>
        </div>
      </form>
    </BaseCard>

    <!-- Filters -->
    <BaseCard>
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <BaseSelect
          v-model="store.filters.scope"
          label="Scope"
          :clearable="false"
          :options="[{ value: 'mine', label: 'Mine' }, { value: 'team', label: 'Team' }]"
          @change="store.fetch()"
        />
        <BaseSelect
          v-model="store.filters.state"
          label="State"
          placeholder="All"
          :options="[{ value: 'open', label: 'Open' }, { value: 'done', label: 'Done' }]"
          @change="store.fetch()"
        />
        <BaseSelect
          v-model="store.filters.priority"
          label="Priority"
          placeholder="All"
          :options="[{ value: 'high', label: 'High' }, { value: 'normal', label: 'Normal' }, { value: 'low', label: 'Low' }]"
          @change="store.fetch()"
        />
        <label class="flex items-center gap-2 pt-6">
          <input v-model="store.filters.overdue" type="checkbox" @change="store.fetch()" />
          <span class="text-sm">Overdue only</span>
        </label>
      </div>
    </BaseCard>

    <BaseCard>
      <p v-if="store.loading" class="py-4 text-center text-sm opacity-60">Loading…</p>
      <div v-else class="space-y-6">
        <section>
          <h2 class="mb-2 text-sm font-semibold uppercase opacity-60">Open ({{ openTasks.length }})</h2>
          <ul class="divide-y divide-border">
            <li v-for="t in openTasks" :key="t.id" class="flex items-center gap-3 py-2">
              <div class="min-w-0 flex-1">
                <p class="truncate font-medium" :class="priorityClass(t.priority)">{{ t.title }}</p>
                <p class="text-xs opacity-70">
                  <span :class="{ 'text-danger': isOverdue(t) }">Due {{ formatDue(t.due_at) }}</span>
                  · {{ t.assigned_to?.name ?? 'Unassigned' }}
                  <span v-if="t.subject_label"> · {{ t.subject_label }}</span>
                </p>
              </div>
              <BaseButton variant="ghost" @click="store.complete(t.id)">Done</BaseButton>
              <BaseButton variant="ghost" @click="cancelTask(t)">Cancel</BaseButton>
            </li>
            <li v-if="!openTasks.length" class="py-4 text-center text-sm opacity-60">
              Nothing open. Nice.
            </li>
          </ul>
        </section>

        <section v-if="doneTasks.length">
          <h2 class="mb-2 text-sm font-semibold uppercase opacity-60">Done ({{ doneTasks.length }})</h2>
          <ul class="divide-y divide-border">
            <li v-for="t in doneTasks" :key="t.id" class="py-2">
              <p class="truncate opacity-60 line-through">{{ t.title }}</p>
            </li>
          </ul>
        </section>
      </div>
    </BaseCard>
  </div>
</template>
