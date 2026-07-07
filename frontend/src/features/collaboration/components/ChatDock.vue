<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import Avatar from 'primevue/avatar'
import { useAuthStore } from '@/features/settings/store'
import { useChatStore } from '@/features/collaboration/chatStore'
import { useChatDockStore } from '@/features/collaboration/chatDockStore'
import { useNotificationsStore } from '@/features/collaboration/notificationsStore'
import ChatDockWindow from '@/features/collaboration/components/ChatDockWindow.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useNativePhone } from '@/composables/useNativeMode'
import { initials, timeAgo } from '@/utils/format'

// Facebook-style chat dock, mounted once in the AppShell: unread conversations
// float as circles at the bottom-right; threads pop open as mini windows lined
// up beside the launcher, several at a time. The launcher panel searches
// conversations and people (to start a chat that doesn't exist yet).
const auth = useAuthStore()
const route = useRoute()
const chat = useChatStore()
const dock = useChatDockStore()
const notifications = useNotificationsStore()

// Android-shell phones: no floating dock — it would fight the bottom tab bar,
// and Chat has its own tab there. The component must stay MOUNTED though: its
// onMounted() subscription is what delivers live chat (and the pop sound) for
// users whose role hides the notification bell.
const nativePhone = useNativePhone()

// The full /chat page has its own inbox and drives the chat store's single
// active thread — the dock suspends there (windows keep their ids and come
// back when you leave, like Facebook's chat tabs).
const onChatPage = computed(() => route.path === '/chat' || route.path.startsWith('/chat/'))
watch(
  onChatPage,
  (v) => {
    dock.suspended = v
    if (v) dock.panelOpen = false
  },
  { immediate: true },
)

onMounted(() => {
  // The dock rides the same per-user channel as the bell. subscribe() is
  // idempotent; calling it here covers users whose role hides the bell.
  notifications.subscribe(auth.user?.id)
  if (!chat.conversations.length) chat.fetchConversations()
})

// Windows render left→right oldest→newest, so the newest sits by the launcher.
const windows = computed(() => [...dock.openIds].reverse())

// ── Launcher panel: search conversations, or people to start a new chat ──
const query = ref('')
watch(
  () => dock.panelOpen,
  (open) => {
    if (open) query.value = ''
  },
)

const shownConversations = computed(() => {
  const list = [...chat.conversations].sort(
    (a, b) => new Date(b.last_message_at ?? 0) - new Date(a.last_message_at ?? 0),
  )
  const q = query.value.trim().toLowerCase()
  if (!q) return list.slice(0, 8)
  return list.filter((c) => (c.title ?? '').toLowerCase().includes(q)).slice(0, 8)
})

// People matching the search with no direct thread yet — clicking starts one
// (the server dedupes 1:1s, so an existing thread is simply reopened).
const shownContacts = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return []
  const directTitles = new Set(
    chat.conversations.filter((c) => c.type === 'direct').map((c) => c.title),
  )
  return chat.contacts
    .filter((u) => u.name?.toLowerCase().includes(q) && !directTitles.has(u.name))
    .slice(0, 6)
})

const startingId = ref(null)
async function startChat(userId) {
  if (startingId.value) return
  startingId.value = userId
  try {
    dock.open(await chat.startDirect(userId))
  } finally {
    startingId.value = null
  }
}
</script>

<template>
  <div
    v-if="!dock.suspended && !nativePhone"
    class="fixed bottom-20 right-3 z-40 flex items-end gap-2.5 pb-[env(safe-area-inset-bottom)] lg:bottom-5 lg:right-5"
  >
    <!-- Popup threads, side by side next to the launcher column -->
    <ChatDockWindow v-for="id in windows" :key="id" :conversation-id="id" />

    <!-- Right column: panel above heads above launcher -->
    <div class="flex flex-col items-end gap-2.5">
      <!-- Recent conversations / search panel -->
      <div
        v-if="dock.panelOpen"
        class="w-80 max-w-[calc(100vw-1.5rem)] overflow-hidden rounded-xl border border-line bg-card shadow-card"
      >
        <div class="flex items-center justify-between border-b border-line px-4 py-2.5">
          <span class="text-sm font-semibold text-ink">Chats</span>
          <RouterLink
            to="/chat"
            class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
          >
            See all
          </RouterLink>
        </div>

        <div class="border-b border-line p-2">
          <input
            v-model="query"
            type="search"
            placeholder="Search or start a new chat…"
            aria-label="Search conversations or people"
            class="w-full rounded-full border border-line bg-ground px-3.5 py-2 text-sm text-ink outline-none transition-colors focus:border-primary"
          />
        </div>

        <div class="max-h-80 overflow-y-auto">
          <p
            v-if="chat.loadingList && !chat.conversations.length"
            class="px-4 py-6 text-center text-sm text-mute"
          >
            Loading…
          </p>
          <EmptyState
            v-else-if="!shownConversations.length && !shownContacts.length"
            icon="pi pi-comments"
            :title="query ? 'No matches' : 'No conversations yet'"
          />

          <ul v-if="shownConversations.length" class="divide-y divide-line">
            <li v-for="c in shownConversations" :key="c.id">
              <button
                type="button"
                class="flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors hover:bg-surface-50 dark:hover:bg-surface-800"
                @click="dock.open(c.id)"
              >
                <Avatar
                  :label="initials(c.title ?? 'C')"
                  shape="circle"
                  class="!bg-highlight !text-primary-700 dark:!text-primary-300"
                />
                <span class="min-w-0 flex-1">
                  <span
                    class="block truncate text-sm text-ink"
                    :class="{ 'font-semibold': c.unread_count > 0 }"
                  >
                    {{ c.title ?? 'Conversation' }}
                  </span>
                  <span class="block truncate text-xs text-mute">
                    {{
                      c.last_message?.preview ||
                      (c.last_message_at ? timeAgo(c.last_message_at) : 'No messages yet')
                    }}
                  </span>
                </span>
                <span
                  v-if="c.unread_count > 0"
                  class="num flex h-5 min-w-[1.25rem] shrink-0 items-center justify-center rounded-full bg-danger px-1.5 text-[10px] font-semibold leading-none text-white"
                >
                  {{ c.unread_count > 99 ? '99+' : c.unread_count }}
                </span>
              </button>
            </li>
          </ul>

          <!-- People without a thread yet -->
          <template v-if="shownContacts.length">
            <p
              class="border-t border-line px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-mute"
            >
              Start new chat
            </p>
            <ul class="divide-y divide-line">
              <li v-for="u in shownContacts" :key="u.id">
                <button
                  type="button"
                  class="flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors hover:bg-surface-50 disabled:opacity-60 dark:hover:bg-surface-800"
                  :disabled="startingId !== null"
                  @click="startChat(u.id)"
                >
                  <Avatar
                    :label="initials(u.name)"
                    shape="circle"
                    class="!bg-highlight !text-primary-700 dark:!text-primary-300"
                  />
                  <span class="min-w-0 flex-1 truncate text-sm text-ink">{{ u.name }}</span>
                  <i
                    :class="startingId === u.id ? 'pi pi-spinner pi-spin' : 'pi pi-user-plus'"
                    class="shrink-0 text-xs text-mute"
                    aria-hidden="true"
                  />
                </button>
              </li>
            </ul>
          </template>
        </div>
      </div>

      <!-- Unread heads -->
      <TransitionGroup name="page" tag="div" class="flex flex-col items-end gap-2">
        <div v-for="c in dock.heads" :key="c.id" class="group relative">
          <button
            v-tooltip.left="`${c.title ?? 'Conversation'} — ${c.last_message?.preview ?? 'new message'}`"
            type="button"
            class="flex h-11 w-11 items-center justify-center rounded-full border border-line bg-card text-sm font-semibold text-primary-700 shadow-card transition-transform hover:scale-105 dark:text-primary-300"
            :aria-label="`Open chat with ${c.title ?? 'conversation'} (${c.unread_count} unread)`"
            @click="dock.open(c.id)"
          >
            {{ initials(c.title ?? 'C') }}
          </button>
          <span
            class="num pointer-events-none absolute -right-1 -top-1 flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-danger px-1 text-[10px] font-semibold leading-none text-white"
          >
            {{ c.unread_count > 99 ? '99+' : c.unread_count }}
          </span>
          <button
            type="button"
            class="absolute -left-1 -top-1 hidden h-4 w-4 items-center justify-center rounded-full bg-surface-500 text-white group-hover:flex"
            :aria-label="`Dismiss ${c.title ?? 'conversation'}`"
            @click.stop="dock.dismiss(c.id)"
          >
            <i class="pi pi-times text-[8px]" aria-hidden="true" />
          </button>
        </div>
      </TransitionGroup>

      <!-- Launcher -->
      <span class="relative inline-block">
        <button
          type="button"
          class="flex h-12 w-12 items-center justify-center rounded-full bg-primary text-primary-contrast shadow-card transition-transform hover:scale-105"
          :aria-label="
            dock.panelOpen
              ? 'Close chats panel'
              : `Open chats${dock.totalUnread > 0 ? ` (${dock.totalUnread} unread)` : ''}`
          "
          @click="dock.togglePanel()"
        >
          <i
            :class="dock.panelOpen ? 'pi pi-times' : 'pi pi-comments'"
            class="text-lg"
            aria-hidden="true"
          />
        </button>
        <span
          v-if="dock.totalUnread > 0 && !dock.panelOpen"
          class="num pointer-events-none absolute -right-1 -top-1 flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-danger px-1 text-[10px] font-semibold leading-none text-white"
        >
          {{ dock.totalUnread > 99 ? '99+' : dock.totalUnread }}
        </span>
      </span>
    </div>
  </div>
</template>
