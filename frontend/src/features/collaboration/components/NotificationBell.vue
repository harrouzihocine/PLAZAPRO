<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import OverlayBadge from 'primevue/overlaybadge'
import Popover from 'primevue/popover'
import { useAuthStore } from '@/features/settings/store'
import { useNotificationsStore } from '@/features/collaboration/notificationsStore'
import { timeAgo } from '@/utils/format'
import EmptyState from '@/components/ui/EmptyState.vue'

// Bell + panel for the AppShell. Loads the latest notifications over HTTP and
// keeps the unread badge live over Reverb. Clicking an item marks it read and
// deep-links to its subject.
const auth = useAuthStore()
const store = useNotificationsStore()
const router = useRouter()
const panel = ref(null)

onMounted(async () => {
  await store.fetch()
  store.subscribe(auth.user?.id)
})

async function activate(n) {
  await store.markRead(n.id)
  panel.value?.hide()
  if (n.link) router.push(n.link)
}
</script>

<template>
  <div>
    <OverlayBadge
      v-if="store.unreadCount > 0"
      :value="store.unreadCount > 99 ? '99+' : String(store.unreadCount)"
      severity="danger"
      size="small"
    >
      <Button
        icon="pi pi-bell"
        text
        rounded
        severity="secondary"
        :aria-label="`Notifications (${store.unreadCount} unread)`"
        @click="panel.toggle($event)"
      />
    </OverlayBadge>
    <Button
      v-else
      icon="pi pi-bell"
      text
      rounded
      severity="secondary"
      aria-label="Notifications"
      @click="panel.toggle($event)"
    />

    <Popover ref="panel" class="w-96 max-w-[92vw]" :pt="{ content: { class: '!p-0' } }">
      <div class="flex items-center justify-between border-b border-line px-4 py-3">
        <span class="text-sm font-semibold text-ink">Notifications</span>
        <Button
          v-if="store.unreadCount > 0"
          label="Mark all read"
          size="small"
          text
          @click="store.markAllRead()"
        />
      </div>

      <div class="max-h-[60vh] overflow-y-auto">
        <p v-if="store.loading" class="px-4 py-8 text-center text-sm text-mute">Loading…</p>
        <EmptyState
          v-else-if="store.items.length === 0"
          icon="pi pi-bell-slash"
          title="You're all caught up"
        />

        <ul v-else class="divide-y divide-line">
          <li v-for="n in store.items" :key="n.id">
            <button
              type="button"
              class="flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-surface-50 dark:hover:bg-surface-800"
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
          </li>
        </ul>
      </div>
    </Popover>
  </div>
</template>
