<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import MessageBubble from '@/features/collaboration/components/MessageBubble.vue'
import ChatMediaViewer from '@/features/collaboration/components/ChatMediaViewer.vue'
import { useChatStore } from '@/features/collaboration/chatStore'
import { useAuthStore } from '@/features/settings/store'
import { confirmAction } from '@/composables/useConfirm'
import { formatDate } from '@/utils/format'
import { t } from '@/i18n'

// The scrollable message pane: day chips, Messenger-style grouped runs,
// scroll-up history loading (scroll anchor preserved), typing indicator,
// seen-tick derivation and the full-screen media viewer. Used by the thread
// page/two-pane and — with `compact` — by the dock windows.
const props = defineProps({
  conversationId: { type: Number, required: true },
  compact: { type: Boolean, default: false },
})
const emit = defineEmits(['reply', 'edit', 'forward'])

const store = useChatStore()
const auth = useAuthStore()
const scroller = ref(null)

const thread = computed(() => store.thread(props.conversationId))
const conversation = computed(() => store.conversation(props.conversationId))
const canPost = computed(() => conversation.value?.can_post ?? true)
const typing = computed(() => store.typingIn(props.conversationId))

// ── Seen ticks: my message is seen once every OTHER participant's read
// cursor passed its created_at (live via the conversation.read broadcast). ──
const otherCursors = computed(() => {
  const me = auth.user?.id
  return (conversation.value?.participants ?? [])
    .filter((p) => p.id !== me)
    .map((p) => (p.last_read_at ? new Date(p.last_read_at).getTime() : 0))
})

function isSeen(m) {
  if (!m.is_mine || m.pending || m.failed) return false
  const cursors = otherCursors.value
  if (!cursors.length) return false
  const at = new Date(m.created_at).getTime()
  return cursors.every((c) => c >= at)
}

// ── Rows: day chips between calendar days + grouped runs (7-min window). ──
const GROUP_WINDOW_MS = 7 * 60 * 1000

function sameGroup(a, b) {
  if (!a || !b || a.is_mine !== b.is_mine) return false
  if ((a.author?.id ?? null) !== (b.author?.id ?? null)) return false
  const dayA = String(a.created_at ?? '').slice(0, 10)
  const dayB = String(b.created_at ?? '').slice(0, 10)
  const gap = Math.abs(new Date(b.created_at).getTime() - new Date(a.created_at).getTime())
  return dayA === dayB && gap < GROUP_WINDOW_MS
}

function dayLabel(value) {
  const d = new Date(value)
  const today = new Date()
  const yesterday = new Date(today)
  yesterday.setDate(today.getDate() - 1)
  if (d.toDateString() === today.toDateString()) return t('common.today')
  if (d.toDateString() === yesterday.toDateString()) return t('common.yesterday')
  return formatDate(value)
}

const rows = computed(() => {
  const out = []
  let prevDay = null
  const messages = thread.value.messages
  for (let i = 0; i < messages.length; i++) {
    const m = messages[i]
    const day = String(m.created_at ?? '').slice(0, 10)
    if (!props.compact && day && day !== prevDay) {
      out.push({ kind: 'day', key: `day-${day}`, label: dayLabel(m.created_at) })
      prevDay = day
    }
    out.push({
      kind: 'msg',
      key: m.id,
      m,
      groupFirst: !sameGroup(messages[i - 1], m),
      groupLast: !sameGroup(m, messages[i + 1]),
    })
  }
  return out
})

// ── Scrolling: pin to bottom on open/own sends/near-bottom arrivals; load
// older history at the top, keeping the viewport anchored. ──
// Where the user last was, from the last scroll event (see onViewportResize).
let wasAtBottom = true

function scrollToBottom() {
  wasAtBottom = true
  nextTick(() => {
    const el = scroller.value
    if (el) el.scrollTop = el.scrollHeight
  })
}

function nearBottom() {
  const el = scroller.value
  if (!el) return true
  return el.scrollHeight - el.scrollTop - el.clientHeight < 140
}

let lastTailId = null
watch(
  () => thread.value.messages.length,
  (len, prev) => {
    if (len === 0) return
    const last = thread.value.messages[len - 1]
    // History prepends grow the array but keep the same tail — the scroll
    // anchor in onScroll owns those; only APPENDS may pin to the bottom.
    const appended = last?.id !== lastTailId
    lastTailId = last?.id ?? null
    if (!appended) return
    if (prev === 0 || last?.is_mine || nearBottom()) scrollToBottom()
  },
)
watch(
  () => props.conversationId,
  () => {
    lastTailId = null
    scrollToBottom()
  },
)
watch(
  () => typing.value.length,
  (n) => n && nearBottom() && scrollToBottom(),
)
onMounted(scrollToBottom)

// The soft keyboard (windowSoftInputMode=adjustResize) shrinks the viewport
// out from under the scroller. `nearBottom()` can't decide AFTER the shrink —
// the distance to the bottom just grew by the keyboard's height — so lean on
// wasAtBottom from before the resize, and keep a reader of the latest
// messages pinned to them (also covers rotation).
function onViewportResize() {
  if (wasAtBottom) scrollToBottom()
}
onMounted(() => window.addEventListener('resize', onViewportResize))
onBeforeUnmount(() => window.removeEventListener('resize', onViewportResize))

async function onScroll() {
  wasAtBottom = nearBottom()
  const el = scroller.value
  if (!el || el.scrollTop > 60) return
  const t = thread.value
  if (t.loadingOlder || t.oldestReached || !t.loaded) return
  const before = el.scrollHeight
  const added = await store.loadOlder(props.conversationId)
  if (added > 0) {
    await nextTick()
    el.scrollTop += el.scrollHeight - before
  }
}

// ── Bubble events ──
function jump(messageId) {
  const el = scroller.value?.querySelector(`[data-mid="${messageId}"]`)
  if (!el) return
  el.scrollIntoView({ behavior: 'smooth', block: 'center' })
  el.classList.add('chat-jump-flash')
  setTimeout(() => el.classList.remove('chat-jump-flash'), 1200)
}

async function remove(message) {
  if (
    await confirmAction({
      title: t('chat.deleteMessageTitle'),
      text: t('chat.deleteMessageText'),
      confirmText: t('common.delete'),
      danger: true,
    })
  ) {
    store.deleteMessage(props.conversationId, message.id)
  }
}

function react({ message, emoji }) {
  if (message.pending || message.failed) return
  store.react(message, emoji)
}

// ── Media viewer: every image of the loaded thread, oldest first. ──
const viewerOpen = ref(false)
const viewerIndex = ref(0)
const images = computed(() =>
  thread.value.messages
    .filter((m) => !m.redacted)
    .flatMap((m) => (m.attachments ?? []).filter((a) => a.kind === 'image'))
    .map((a) => ({ id: a.id, url: a.url })),
)

function openMedia(attachment) {
  const idx = images.value.findIndex((i) => i.id === attachment.id)
  viewerIndex.value = idx === -1 ? 0 : idx
  viewerOpen.value = true
}
</script>

<template>
  <div
    ref="scroller"
    class="flex-1 space-y-2 overflow-y-auto overscroll-contain bg-ground"
    :class="compact ? 'px-3 py-3' : 'px-3 py-4 sm:px-4'"
    @scroll.passive="onScroll"
  >
    <p v-if="thread.loadingOlder" class="py-2 text-center text-xs text-mute">
      <i class="pi pi-spinner pi-spin" aria-hidden="true" /> Loading history…
    </p>
    <p
      v-else-if="thread.oldestReached && thread.messages.length > 20 && !compact"
      class="py-2 text-center text-[11px] text-mute"
    >
      Beginning of the conversation
    </p>

    <template v-for="r in rows" :key="r.key">
      <div v-if="r.kind === 'day'" class="flex justify-center py-1">
        <span
          class="rounded-full bg-surface-100 px-3 py-1 text-[11px] font-medium text-mute dark:bg-surface-800"
        >
          {{ r.label }}
        </span>
      </div>

      <div v-else :data-mid="r.m.id" class="rounded-xl transition-colors">
        <MessageBubble
          :m="r.m"
          :group-first="r.groupFirst"
          :group-last="r.groupLast"
          :compact="compact"
          :can-post="canPost"
          :seen="isSeen(r.m)"
          @reply="emit('reply', $event)"
          @edit="emit('edit', $event)"
          @forward="emit('forward', $event)"
          @react="react"
          @delete="remove"
          @open-media="openMedia"
          @jump="jump"
          @retry="store.retrySend(conversationId, $event)"
          @discard="store.discardPending(conversationId, $event.client_key)"
        />
      </div>
    </template>

    <!-- Typing indicator -->
    <div v-if="typing.length" class="flex items-end gap-1.5">
      <div
        class="flex items-center gap-1.5 rounded-2xl rounded-es-md border border-line bg-card px-3.5 py-2.5 shadow-card native:shadow-none"
      >
        <span class="chat-typing-dot" />
        <span class="chat-typing-dot" style="animation-delay: 0.15s" />
        <span class="chat-typing-dot" style="animation-delay: 0.3s" />
        <span v-if="typing.length === 1" class="ms-1 text-[11px] text-mute">
          {{ typing[0].name }}
        </span>
      </div>
    </div>

    <p
      v-if="thread.loaded && !thread.messages.length"
      class="py-8 text-center text-sm text-mute"
    >
      No messages yet. Say hello.
    </p>

    <ChatMediaViewer
      :open="viewerOpen"
      :items="images"
      :start-index="viewerIndex"
      @close="viewerOpen = false"
    />
  </div>
</template>

<style scoped>
.chat-typing-dot {
  height: 6px;
  width: 6px;
  border-radius: 9999px;
  background: currentColor;
  color: var(--p-surface-400, #9ca3af);
  animation: chat-typing-bounce 1s infinite ease-in-out;
}
@keyframes chat-typing-bounce {
  0%,
  60%,
  100% {
    transform: translateY(0);
    opacity: 0.5;
  }
  30% {
    transform: translateY(-4px);
    opacity: 1;
  }
}
:deep(.chat-jump-flash) {
  background: color-mix(in srgb, var(--p-primary-400, #b3903f) 25%, transparent);
}
</style>
