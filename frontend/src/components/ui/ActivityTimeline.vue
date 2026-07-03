<script setup>
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import { useApi } from '@/composables/useApi'
import { formatDateTime, humanize, timeAgo } from '@/utils/format'
import EmptyState from '@/components/ui/EmptyState.vue'

// The audit trail of one record, rendered as a timeline. Backed by the
// per-record activity endpoint (access follows the record's view permission).
const props = defineProps({
  type: { type: String, required: true }, // morph alias: unit, location, client, ...
  id: { type: [Number, String], required: true },
  limit: { type: Number, default: null }, // compact mode: show at most N entries, no paging
})

const api = useApi()
const items = ref([])
const loading = ref(false)
const error = ref(false)
const page = ref(1)
const lastPage = ref(1)

const ICONS = {
  create: 'pi pi-plus',
  update: 'pi pi-pencil',
  cancel: 'pi pi-ban',
  archive: 'pi pi-inbox',
  restore: 'pi pi-undo',
  reactivate: 'pi pi-undo',
  duplicate: 'pi pi-history',
  export: 'pi pi-download',
}

const TONES = {
  create: 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300',
  update: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
  cancel: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
  archive: 'bg-surface-100 text-surface-600 dark:bg-surface-800 dark:text-surface-300',
}

// Noise fields nobody needs to read in a diff.
const HIDDEN_FIELDS = new Set(['updated_at', 'created_at', 'id'])

function diffOf(entry) {
  const changes = entry.changes
  if (!changes || entry.action !== 'update' || !changes.after) return []
  return Object.keys(changes.after)
    .filter((k) => !HIDDEN_FIELDS.has(k))
    .map((k) => ({ field: k, before: changes.before?.[k], after: changes.after[k] }))
}

function reasonOf(entry) {
  return entry.changes?.reason ?? null
}

function display(value) {
  if (value === null || value === undefined || value === '') return '∅'
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

const visible = computed(() => (props.limit ? items.value.slice(0, props.limit) : items.value))
const hasMore = computed(() => !props.limit && page.value < lastPage.value)

async function load(reset = false) {
  if (reset) {
    page.value = 1
    items.value = []
  }
  loading.value = true
  error.value = false
  try {
    const res = await api.get(`/activity/${props.type}/${props.id}`, {
      params: { page: page.value },
    })
    items.value = reset ? res.data.data : [...items.value, ...res.data.data]
    lastPage.value = res.data.meta?.last_page ?? 1
  } catch {
    error.value = true
  } finally {
    loading.value = false
  }
}

function loadMore() {
  page.value += 1
  load()
}

watch(
  () => [props.type, props.id],
  () => load(true),
  { immediate: true },
)
</script>

<template>
  <div>
    <div v-if="loading && items.length === 0" class="space-y-4 py-1">
      <div v-for="i in 3" :key="i" class="flex gap-3">
        <Skeleton shape="circle" size="2rem" />
        <div class="flex-1 space-y-2">
          <Skeleton width="40%" height="0.9rem" />
          <Skeleton width="70%" height="0.8rem" />
        </div>
      </div>
    </div>

    <EmptyState
      v-else-if="error"
      icon="pi pi-exclamation-triangle"
      title="Couldn't load the history"
      body="You may not have permission to read this record's activity."
    />

    <EmptyState
      v-else-if="items.length === 0"
      icon="pi pi-clock"
      title="No activity yet"
      body="Changes to this record will appear here."
    />

    <ol v-else class="relative space-y-0">
      <li v-for="(entry, i) in visible" :key="entry.id" class="relative flex gap-3 pb-5">
        <!-- connector line -->
        <span
          v-if="i < visible.length - 1"
          class="absolute left-4 top-9 h-[calc(100%-2rem)] w-px -translate-x-1/2 bg-line"
          aria-hidden="true"
        />
        <span
          class="z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs"
          :class="TONES[entry.action] ?? 'bg-highlight text-primary-700 dark:text-primary-300'"
        >
          <i :class="ICONS[entry.action] ?? 'pi pi-circle'" aria-hidden="true" />
        </span>

        <div class="min-w-0 flex-1 pt-1">
          <p class="text-sm text-ink">
            <span class="font-semibold">{{ entry.user_name ?? 'System' }}</span>
            <span class="text-mute"> · {{ humanize(entry.action) }}d</span>
            <span
              v-tooltip.top="formatDateTime(entry.created_at)"
              class="cursor-default text-xs text-mute"
            >
              · {{ timeAgo(entry.created_at) }}</span
            >
          </p>

          <p v-if="reasonOf(entry)" class="mt-1 text-sm italic text-mute">
            “{{ reasonOf(entry) }}”
          </p>

          <ul v-if="diffOf(entry).length" class="mt-1.5 space-y-1">
            <li
              v-for="d in diffOf(entry)"
              :key="d.field"
              class="flex flex-wrap items-center gap-1.5 text-xs"
            >
              <span class="font-medium text-mute">{{ humanize(d.field) }}</span>
              <span
                class="num rounded bg-surface-100 px-1.5 py-0.5 text-mute line-through dark:bg-surface-800"
              >
                {{ display(d.before) }}
              </span>
              <i class="pi pi-arrow-right text-[9px] text-mute" aria-hidden="true" />
              <span class="num rounded bg-highlight px-1.5 py-0.5 font-medium text-ink">
                {{ display(d.after) }}
              </span>
            </li>
          </ul>
        </div>
      </li>
    </ol>

    <div v-if="hasMore" class="pt-1 text-center">
      <Button
        label="Load older activity"
        icon="pi pi-chevron-down"
        text
        size="small"
        :loading="loading"
        @click="loadMore"
      />
    </div>
  </div>
</template>
