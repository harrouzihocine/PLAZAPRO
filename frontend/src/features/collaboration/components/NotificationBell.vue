<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Popover from 'primevue/popover'
import { useAuthStore } from '@/features/settings/store'
import { useNotificationsStore } from '@/features/collaboration/notificationsStore'
import { timeAgo } from '@/utils/format'
import EmptyState from '@/components/ui/EmptyState.vue'
import NotificationPrefsModal from '@/features/settings/components/NotificationPrefsModal.vue'

// Bell + panel for the AppShell. Loads the latest notifications over HTTP and
// keeps the unread badge live over Reverb. Clicking an item marks it read and
// deep-links to its subject.
const auth = useAuthStore()
const store = useNotificationsStore()
const router = useRouter()
const panel = ref(null)
const showPrefs = ref(false)

function openPrefs() {
  panel.value?.hide()
  showPrefs.value = true
}

onMounted(async () => {
  await store.fetch()
  store.subscribe(auth.user?.id)
})

async function activate(n) {
  await store.markRead(n.id)
  panel.value?.hide()
  if (n.link) router.push(n.link)
}

// Lazy-load older pages as the panel scrolls near the bottom. loadMore() no-ops
// if already loading or there's no next page, so no guard is needed here.
function onScroll(e) {
  const el = e.target
  if (el.scrollTop + el.clientHeight >= el.scrollHeight - 64) {
    store.loadMore()
  }
}
</script>

<template>
  <div>
    <span class="relative inline-block">
      <Button
        icon="pi pi-bell"
        text
        rounded
        severity="secondary"
        :aria-label="`Notifications${store.unreadCount > 0 ? ` (${store.unreadCount} unread)` : ''}`"
        @click="panel.toggle($event)"
      />
      <span
        v-if="store.unreadCount > 0"
        class="pointer-events-none absolute -top-1 -right-1 flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-danger px-1 text-[10px] font-semibold leading-none text-white"
      >
        {{ store.unreadCount > 99 ? '99+' : store.unreadCount }}
      </span>
    </span>

    <Popover ref="panel" class="w-96 max-w-[92vw]" :pt="{ content: { class: '!p-0' } }">
      <div class="flex items-center justify-between border-b border-line px-4 py-3">
        <span class="text-sm font-semibold text-ink">Notifications</span>
        <span class="flex items-center gap-1">
          <Button
            v-if="store.unreadCount > 0"
            label="Mark all read"
            size="small"
            text
            @click="store.markAllRead()"
          />
          <Button
            icon="pi pi-cog"
            size="small"
            text
            rounded
            severity="secondary"
            aria-label="Notification settings"
            @click="openPrefs"
          />
        </span>
      </div>

      <div class="max-h-[60vh] overflow-y-auto" @scroll="onScroll">
        <p v-if="store.loading" class="px-4 py-8 text-center text-sm text-mute">Loading…</p>
        <EmptyState
          v-else-if="store.items.length === 0"
          icon="pi pi-bell-slash"
          title="You're all caught up"
        />

        <ul v-else class="divide-y divide-line">
          <li v-for="n in store.items" :key="n.id" class="relative">
            <button
              type="button"
              class="flex w-full items-start gap-3 py-3 pl-4 pr-10 text-left transition-colors hover:bg-surface-50 dark:hover:bg-surface-800"
              @click="activate(n)"
            >
              <span
                class="mt-1.5 h-2 w-2 shrink-0 rounded-full"
                :class="n.read_at ? 'bg-transparent' : 'bg-primary'"
                aria-hidden="true"
              />
              <span class="min-w-0 flex-1">
                <span
                  class="block truncate text-sm text-ink"
                  :class="{ 'font-semibold': !n.read_at }"
                >
                  {{ n.title }}
                </span>
                <span v-if="n.body" class="mt-0.5 block text-xs text-mute">{{ n.body }}</span>
                <span class="mt-0.5 block text-[11px] text-mute">{{ timeAgo(n.created_at) }}</span>
              </span>
            </button>

            <button
              v-if="!n.read_at"
              v-tooltip.left="'Mark as read'"
              type="button"
              class="absolute right-2 top-3 flex h-6 w-6 items-center justify-center rounded-full text-mute transition-colors hover:bg-surface-100 hover:text-ink dark:hover:bg-surface-700"
              :aria-label="`Mark '${n.title}' as read`"
              @click.stop="store.markRead(n.id)"
            >
              <i class="pi pi-check text-xs" aria-hidden="true" />
            </button>
            <button
              v-else
              v-tooltip.left="'Mark as unread'"
              type="button"
              class="absolute right-2 top-3 flex h-6 w-6 items-center justify-center rounded-full text-mute transition-colors hover:bg-surface-100 hover:text-ink dark:hover:bg-surface-700"
              :aria-label="`Mark '${n.title}' as unread`"
              @click.stop="store.markUnread(n.id)"
            >
              <i class="pi pi-undo text-xs" aria-hidden="true" />
            </button>
          </li>
        </ul>

        <p v-if="store.loadingMore" class="px-4 py-3 text-center text-xs text-mute">Loading more…</p>
      </div>
    </Popover>

    <NotificationPrefsModal v-if="showPrefs" @close="showPrefs = false" />
  </div>
</template>
