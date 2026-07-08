<script setup>
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import { useApi } from '@/composables/useApi'
import { formatDateTime, humanize, timeAgo } from '@/utils/format'
import { t } from '@/i18n'
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

// Marker chip tone per action (icon + ring on the rail).
const TONES = {
  create: 'bg-green-100 text-green-700 ring-green-200 dark:bg-green-500/15 dark:text-green-300 dark:ring-green-500/25',
  update: 'bg-sky-100 text-sky-700 ring-sky-200 dark:bg-sky-500/15 dark:text-sky-300 dark:ring-sky-500/25',
  cancel: 'bg-red-100 text-red-700 ring-red-200 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-500/25',
  archive: 'bg-surface-100 text-surface-600 ring-surface-200 dark:bg-surface-800 dark:text-surface-300 dark:ring-surface-700',
}

// Past-tense labels for the action pill, localized (activity.action.*).
const ACTION_KEYS = new Set([
  'create',
  'update',
  'cancel',
  'archive',
  'restore',
  'reactivate',
  'duplicate',
  'export',
])
const actionLabel = (action) =>
  ACTION_KEYS.has(action) ? t(`activity.action.${action}`) : humanize(action)

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
      :title="$t('activity.loadErrorTitle')"
      :body="$t('activity.loadErrorBody')"
    />

    <EmptyState
      v-else-if="items.length === 0"
      icon="pi pi-clock"
      :title="$t('activity.emptyTitle')"
      :body="$t('activity.emptyBody')"
    />

    <ol v-else class="relative">
      <li v-for="(entry, i) in visible" :key="entry.id" class="relative flex gap-4 pb-7 last:pb-0">
        <!-- connector rail -->
        <span
          v-if="i < visible.length - 1"
          class="absolute start-[15px] top-9 bottom-0 w-px bg-line"
          aria-hidden="true"
        />
        <span
          class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs ring-4 ring-surface-0 dark:ring-surface-950"
          :class="TONES[entry.action] ?? 'bg-highlight text-primary-700 ring-surface-0 dark:text-primary-300 dark:ring-surface-950'"
        >
          <i :class="ICONS[entry.action] ?? 'pi pi-circle'" aria-hidden="true" />
        </span>

        <div class="min-w-0 flex-1">
          <!-- header: who + what, with the time pushed to the right -->
          <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <span
              class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold"
              :class="TONES[entry.action] ?? 'bg-highlight text-primary-700 dark:text-primary-300'"
            >
              {{ actionLabel(entry.action) }}
            </span>
            <span class="text-sm font-medium text-ink">{{ entry.user_name ?? $t('activity.system') }}</span>
            <span
              v-if="entry.role_at_time"
              class="text-xs text-mute"
            >· {{ entry.role_at_time }}</span>
            <span
              v-tooltip.top="formatDateTime(entry.created_at)"
              class="ms-auto shrink-0 cursor-default text-xs text-mute"
            >
              {{ timeAgo(entry.created_at) }}
            </span>
          </div>

          <p v-if="reasonOf(entry)" class="mt-2 border-s-2 border-line ps-3 text-sm italic text-mute">
            {{ reasonOf(entry) }}
          </p>

          <!-- changes: one roomy row per field, label column + before → after -->
          <div
            v-if="diffOf(entry).length"
            class="mt-2.5 overflow-hidden rounded-lg border border-line"
          >
            <div
              v-for="(d, di) in diffOf(entry)"
              :key="d.field"
              class="flex flex-col gap-1 px-3 py-2 sm:flex-row sm:items-baseline sm:gap-3"
              :class="di > 0 ? 'border-t border-line' : ''"
            >
              <span class="shrink-0 text-xs font-medium text-mute sm:w-32">
                {{ humanize(d.field) }}
              </span>
              <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2 text-sm">
                <span class="num break-all text-mute line-through">{{ display(d.before) }}</span>
                <i class="pi pi-arrow-right text-[10px] text-mute" aria-hidden="true" />
                <span class="num break-all font-medium text-ink">{{ display(d.after) }}</span>
              </div>
            </div>
          </div>
        </div>
      </li>
    </ol>

    <div v-if="hasMore" class="pt-1 text-center">
      <Button
        :label="$t('activity.loadOlder')"
        icon="pi pi-chevron-down"
        text
        size="small"
        :loading="loading"
        @click="loadMore"
      />
    </div>
  </div>
</template>
