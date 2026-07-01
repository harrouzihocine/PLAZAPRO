<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/features/settings/store'
import { useNotificationsStore } from '@/features/collaboration/notificationsStore'

// Bell + dropdown for the AppShell. Loads the latest notifications over HTTP and
// keeps the unread badge live over Reverb. Clicking an item marks it read and
// deep-links to its subject.
const auth = useAuthStore()
const store = useNotificationsStore()
const router = useRouter()

const open = ref(false)
const badge = computed(() => (store.unreadCount > 99 ? '99+' : String(store.unreadCount)))

onMounted(async () => {
  await store.fetch()
  store.subscribe(auth.user?.id)
})

function toggle() {
  open.value = !open.value
}

async function activate(n) {
  await store.markRead(n.id)
  open.value = false
  if (n.link) router.push(n.link)
}
</script>

<template>
  <div class="relative">
    <button
      class="relative min-h-[44px] min-w-[44px]"
      :aria-label="`Notifications${store.unreadCount ? ` (${store.unreadCount} unread)` : ''}`"
      @click="toggle"
    >
      <span aria-hidden="true">🔔</span>
      <span
        v-if="store.unreadCount > 0"
        class="absolute right-1 top-1 min-w-[18px] rounded-full bg-danger px-1 text-center text-[10px] font-semibold leading-[18px] text-white"
      >
        {{ badge }}
      </span>
    </button>

    <!-- Click-away backdrop -->
    <div v-if="open" class="fixed inset-0 z-10" @click="open = false" />

    <div
      v-if="open"
      class="absolute right-0 z-20 mt-2 max-h-[70vh] w-80 max-w-[90vw] overflow-y-auto rounded-token border border-border bg-surface shadow-lg"
    >
      <div class="flex items-center justify-between border-b border-border px-3 py-2">
        <span class="font-semibold">Notifications</span>
        <button
          v-if="store.unreadCount > 0"
          class="text-xs text-primary"
          @click="store.markAllRead()"
        >
          Mark all read
        </button>
      </div>

      <p v-if="store.loading" class="px-3 py-6 text-center text-sm text-ink opacity-70">Loading…</p>
      <p
        v-else-if="store.items.length === 0"
        class="px-3 py-6 text-center text-sm text-ink opacity-70"
      >
        You're all caught up.
      </p>

      <ul v-else class="divide-y divide-border">
        <li v-for="n in store.items" :key="n.id">
          <button
            class="flex w-full flex-col gap-0.5 px-3 py-2 text-left hover:bg-bg"
            :class="{ 'font-semibold': !n.read_at }"
            @click="activate(n)"
          >
            <span class="flex items-center gap-2 text-sm">
              <span
                v-if="!n.read_at"
                aria-hidden="true"
                class="h-2 w-2 shrink-0 rounded-full bg-primary"
              />
              {{ n.title }}
            </span>
            <span v-if="n.body" class="text-xs text-ink opacity-70">{{ n.body }}</span>
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>
