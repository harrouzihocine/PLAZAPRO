<script setup>
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import Popover from 'primevue/popover'
import { formatDateTime, humanize } from '@/utils/format'
import { copyToClipboard } from '@/composables/useClipboard'

// Renders personal work (calls / office visits / in-site visits / tasks) as one
// card per type — never mixed — reused by "My upcoming" and "My open & overdue
// actions" on the dashboard so both read the same way. Each item can carry
// unit / location / maps_url; when it does, a small "i" button opens a details
// popover (client, when, unit, site, Google Maps + open link) — the same
// pattern as the dispatch board's task-details popover.
//
// groups: [{ key, label, icon, items }], item: { id, kind, due_at, is_overdue,
//   client?, title?, unit?, location?, maps_url?, link? }
defineProps({
  groups: { type: Array, required: true },
  emptyText: { type: String, default: 'Nothing here.' },
})

const detailsRef = ref(null)
const detailsItem = ref(null)
const detailsIcon = ref('pi pi-info-circle')
function showDetails(event, item, icon) {
  detailsItem.value = item
  detailsIcon.value = icon
  detailsRef.value?.show(event)
}

// A bare client link (no project yet) opens the client file, not a project.
function openLabel(item) {
  if (item.kind === 'task') return 'Open task'
  return item.link?.includes('/projects/') ? 'Open project' : 'Open client'
}
</script>

<template>
  <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
    <div v-for="g in groups" :key="g.key" class="rounded-xl border border-line p-3">
      <p class="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-mute">
        <i :class="g.icon" aria-hidden="true" />
        {{ g.label }}
        <span class="num ms-auto rounded-full bg-surface-100 px-2 py-0.5 text-ink dark:bg-surface-800">
          {{ g.items.length }}
        </span>
      </p>
      <p v-if="!g.items.length" class="py-2 text-sm text-mute">{{ emptyText }}</p>
      <ul v-else class="space-y-1.5">
        <li
          v-for="item in g.items.slice(0, 5)"
          :key="item.id"
          class="flex items-center gap-1 rounded-lg transition-colors hover:bg-surface-50 dark:hover:bg-surface-800"
        >
          <RouterLink :to="item.link ?? '/'" class="min-w-0 flex-1 px-2 py-1.5 text-sm">
            <span class="block truncate font-medium text-ink">
              {{ item.client ?? item.title ?? '—' }}
            </span>
            <span class="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-xs">
              <span class="num" :class="item.is_overdue ? 'font-semibold text-danger' : 'text-mute'">
                {{ formatDateTime(item.due_at) }}
              </span>
              <span v-if="item.unit" class="text-mute">· {{ item.unit }}</span>
              <span v-if="item.location" class="text-mute">· {{ item.location }}</span>
            </span>
          </RouterLink>
          <button
            type="button"
            class="me-1 shrink-0 text-mute transition-colors hover:text-ink"
            aria-label="Details"
            @click.stop.prevent="showDetails($event, item, g.icon)"
          >
            <i class="pi pi-info-circle" aria-hidden="true" />
          </button>
        </li>
        <li v-if="g.items.length > 5" class="px-2 text-xs text-mute">
          … and {{ g.items.length - 5 }} more
        </li>
      </ul>
    </div>

    <!-- Item details — mirrors the dispatch board's popover. -->
    <Popover ref="detailsRef" class="w-80 max-w-[92vw]">
      <div v-if="detailsItem" class="space-y-2 text-sm">
        <p class="flex items-center gap-2 font-semibold text-ink">
          <i :class="detailsIcon" aria-hidden="true" />
          {{ humanize(detailsItem.kind) }}
        </p>
        <dl class="space-y-1.5">
          <div v-if="detailsItem.client" class="flex justify-between gap-2">
            <dt class="text-mute">Client</dt>
            <dd class="text-ink">{{ detailsItem.client }}</dd>
          </div>
          <div v-if="detailsItem.title" class="flex justify-between gap-2">
            <dt class="text-mute">Task</dt>
            <dd class="text-ink">{{ detailsItem.title }}</dd>
          </div>
          <div class="flex justify-between gap-2">
            <dt class="text-mute">When</dt>
            <dd class="num text-ink">{{ formatDateTime(detailsItem.due_at) }}</dd>
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
            <i class="pi pi-folder-open" aria-hidden="true" /> {{ openLabel(detailsItem) }}
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
            title="Copy Maps link"
            aria-label="Copy Maps link"
            class="inline-flex items-center text-primary-600 hover:underline dark:text-primary-400"
            @click="copyToClipboard(detailsItem.maps_url, 'Maps link copied')"
          >
            <i class="pi pi-copy" aria-hidden="true" />
          </button>
        </div>
      </div>
    </Popover>
  </div>
</template>
