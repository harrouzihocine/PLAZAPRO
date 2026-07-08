<script setup>
import { computed, ref } from 'vue'
import Avatar from 'primevue/avatar'
import Badge from 'primevue/badge'
import EmptyState from '@/components/ui/EmptyState.vue'
import ActionSheet from '@/components/ui/ActionSheet.vue'
import { useChatStore } from '@/features/collaboration/chatStore'
import { usePresenceStore } from '@/features/collaboration/presenceStore'
import { useAuthStore } from '@/features/settings/store'
import { initials, timeAgo } from '@/utils/format'
import { isNativeApp } from '@/utils/nativeApp'

// The inbox, WhatsApp-style: search, filter chips (native), presence dots,
// unread pills, muted bells, and a long-press row menu (mark read / mute).
// Emits select(id) — navigation is the parent's business (page vs two-pane).
defineProps({
  selectedId: { type: Number, default: null },
})
const emit = defineEmits(['select'])

const store = useChatStore()
const presence = usePresenceStore()
const auth = useAuthStore()
const isNative = isNativeApp()

const query = ref('')
const filter = ref('all') // all | unread | groups | projects
const FILTERS = [
  { key: 'all', labelKey: 'common.all' },
  { key: 'unread', labelKey: 'chat.unread' },
  { key: 'groups', labelKey: 'chat.groups' },
  { key: 'projects', labelKey: 'inventory.projects' },
]

function otherOf(c) {
  if (c.type !== 'direct') return null
  return c.participants?.find((p) => p.id !== auth.user?.id) ?? null
}

const shown = computed(() => {
  let list = store.conversations
  const q = query.value.trim().toLowerCase()
  if (q) list = list.filter((c) => (c.title ?? '').toLowerCase().includes(q))
  if (filter.value === 'unread') list = list.filter((c) => c.unread_count > 0)
  if (filter.value === 'groups') list = list.filter((c) => c.type === 'group')
  if (filter.value === 'projects') list = list.filter((c) => c.type === 'project')
  return [...list].sort(
    (a, b) => new Date(b.last_message_at ?? 0) - new Date(a.last_message_at ?? 0),
  )
})

// ── Long-press row menu (native) ──
const sheetFor = ref(null)
let pressTimer = null

function pressStart(c) {
  if (!isNative) return
  clearTimeout(pressTimer)
  pressTimer = setTimeout(() => {
    sheetFor.value = c
  }, 450)
}

function pressCancel() {
  clearTimeout(pressTimer)
}

const sheetActions = computed(() => {
  const c = sheetFor.value
  if (!c) return []
  return [
    { key: 'open', label: 'Open', icon: 'pi pi-comment' },
    ...(c.unread_count > 0
      ? [{ key: 'read', label: 'Mark as read', icon: 'pi pi-check-circle' }]
      : []),
    c.is_muted
      ? { key: 'mute', label: 'Unmute notifications', icon: 'pi pi-bell' }
      : { key: 'mute', label: 'Mute notifications', icon: 'pi pi-bell-slash' },
  ]
})

async function onSheetPick(key) {
  const c = sheetFor.value
  sheetFor.value = null
  if (!c) return
  if (key === 'open') emit('select', c.id)
  if (key === 'read') store.markRead(c.id)
  if (key === 'mute') await store.toggleMute(c.id)
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <!-- Search + filter chips -->
    <div class="border-b border-line px-3 pb-2 pt-2 sm:px-4">
      <div class="relative">
        <i
          class="pi pi-search pointer-events-none absolute start-3.5 top-1/2 -translate-y-1/2 text-xs text-mute"
          aria-hidden="true"
        />
        <input
          v-model="query"
          type="search"
:placeholder="$t('chat.searchConversations')"
          :aria-label="$t('chat.searchConversations')"
          class="w-full rounded-full border border-line bg-ground py-2 ps-9 pe-3.5 text-sm text-ink outline-none transition-colors focus:border-primary native:py-2.5"
        />
      </div>
      <div v-if="isNative" class="scrollbar-none -mx-1 mt-2 flex gap-1.5 overflow-x-auto px-1">
        <button
          v-for="f in FILTERS"
          :key="f.key"
          type="button"
          class="shrink-0 rounded-full px-3.5 py-1.5 text-xs font-medium transition-colors"
          :class="
            filter === f.key
              ? 'bg-primary text-primary-contrast'
              : 'bg-surface-100 text-mute dark:bg-surface-800'
          "
          @click="filter = f.key"
        >
          {{ $t(f.labelKey) }}
        </button>
      </div>
    </div>

    <!-- Rows -->
    <div class="min-h-0 flex-1 overflow-y-auto">
      <p v-if="store.loadingList && !store.conversations.length" class="py-8 text-center text-sm text-mute">
        Loading…
      </p>
      <EmptyState
        v-else-if="!shown.length"
        icon="pi pi-comments"
        :title="query || filter !== 'all' ? $t('chat.noMatches') : $t('chat.noConversations')"
        :body="query || filter !== 'all' ? undefined : $t('chat.noConversationsBody')"
      />

      <ul v-else class="divide-y divide-line">
        <li v-for="c in shown" :key="c.id">
          <button
            type="button"
            class="flex w-full items-center gap-3 px-4 py-3 text-start transition-colors hover:bg-surface-50 native:py-3.5 native:active:bg-highlight sm:px-5 dark:hover:bg-surface-800"
            :class="selectedId === c.id && 'bg-highlight hover:!bg-highlight'"
            @click="emit('select', c.id)"
            @touchstart="pressStart(c)"
            @touchend="pressCancel"
            @touchmove="pressCancel"
            @contextmenu.prevent="isNative && (sheetFor = c)"
          >
            <span class="relative shrink-0">
              <Avatar
                :image="(isNative && otherOf(c)?.avatar_url) || undefined"
                :label="isNative && otherOf(c)?.avatar_url ? undefined : initials(c.title ?? 'C')"
                shape="circle"
                size="large"
                class="!bg-highlight !text-primary-700 dark:!text-primary-300"
              />
              <span
                v-if="isNative && otherOf(c) && presence.isOnline(otherOf(c).id)"
                class="absolute bottom-0 end-0 h-3.5 w-3.5 rounded-full border-2 border-card bg-green-500"
                :aria-label="$t('chat.online')"
              />
            </span>
            <span class="min-w-0 flex-1">
              <span class="flex items-center justify-between gap-2">
                <span
                  class="truncate text-sm text-ink"
                  :class="c.unread_count > 0 ? 'font-semibold' : 'font-medium'"
                >
                  <i
                    v-if="c.type === 'project'"
                    class="pi pi-folder me-1 text-xs text-mute"
                    :title="$t('project.projectChat')"
                    aria-hidden="true"
                  />
                  {{ c.title ?? $t('chat.conversation') }}
                </span>
                <span
                  class="num shrink-0 text-xs"
                  :class="c.unread_count > 0 ? 'font-semibold text-primary-600 dark:text-primary-400' : 'text-mute'"
                >
                  {{ timeAgo(c.last_message_at) }}
                </span>
              </span>
              <span class="mt-0.5 flex items-center gap-1.5">
                <span class="min-w-0 flex-1 truncate text-sm text-mute">
                  {{ c.last_message?.preview ?? $t('chat.noMessagesYet') }}
                </span>
                <i
                  v-if="c.is_muted"
                  class="pi pi-bell-slash shrink-0 text-xs text-mute"
                  :title="$t('chat.muted')"
                  aria-hidden="true"
                />
              </span>
            </span>
            <Badge v-if="c.unread_count > 0" :value="c.unread_count" />
          </button>
        </li>
      </ul>
    </div>

    <ActionSheet
      :open="!!sheetFor"
      :title="sheetFor?.title ?? $t('chat.conversation')"
      :actions="sheetActions"
      @close="sheetFor = null"
      @pick="onSheetPick"
    />
  </div>
</template>

<style scoped>
.scrollbar-none {
  scrollbar-width: none;
}
.scrollbar-none::-webkit-scrollbar {
  display: none;
}
</style>
