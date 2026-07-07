<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import Avatar from 'primevue/avatar'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import { useAuthStore } from '@/features/settings/store'
import { useChatStore } from '@/features/collaboration/chatStore'
import MessageComposer from '@/features/collaboration/components/MessageComposer.vue'
import { confirmAction, toastError } from '@/composables/useConfirm'
import { useNativePhone } from '@/composables/useNativeMode'
import { usePresenceStore } from '@/features/collaboration/presenceStore'
import { isNativeApp } from '@/utils/nativeApp'
import { formatDate, formatTime, initials } from '@/utils/format'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const store = useChatStore()
const scroller = ref(null)
const showInfo = ref(false)

// Android shell: on phones the thread takes over the viewport edge-to-edge
// (the AppShell drops its padding and bottom bar on this route); on every
// native size the bubbles get the Messenger treatment — grouped runs, day
// chips, author avatars, presence, always-visible delete (no hover on touch).
const isNative = isNativeApp()
const nativePhone = useNativePhone()
const presence = usePresenceStore()

const otherUser = computed(() =>
  store.active?.type === 'direct'
    ? (store.active?.participants?.find((p) => p.id !== auth.user?.id) ?? null)
    : null,
)
const otherOnline = computed(() => !!otherUser.value && presence.isOnline(otherUser.value.id))

// Messenger-style message runs: consecutive messages by the same author within
// a few minutes group together (name/avatar/time once per run), with day chips
// between calendar days. The web keeps its flat list — rows only differ there
// by carrying the same message objects.
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
  if (d.toDateString() === today.toDateString()) return 'Today'
  if (d.toDateString() === yesterday.toDateString()) return 'Yesterday'
  return formatDate(value)
}

const rows = computed(() => {
  const out = []
  let prevDay = null
  const messages = store.messages
  for (let i = 0; i < messages.length; i++) {
    const m = messages[i]
    const day = String(m.created_at ?? '').slice(0, 10)
    if (isNative && day && day !== prevDay) {
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

const isGroup = computed(() => store.active?.type === 'group')
// Oversight readers (project chats via chat.view_project_chats) see the thread
// but cannot write — the composer locks and a notice explains why.
const canPost = computed(() => store.active?.can_post ?? true)
const iAmAdmin = computed(
  () =>
    store.active?.participants?.some((p) => p.id === auth.user?.id && p.role === 'admin') ?? false,
)
const nonParticipants = computed(() => {
  const ids = new Set(store.active?.participants?.map((p) => p.id) ?? [])
  return store.contacts.filter((c) => !ids.has(c.id))
})

async function load(id) {
  if (!id) return
  try {
    await store.openThread(Number(id))
  } catch (e) {
    // Not readable (not a participant, no oversight grant) → back to the inbox
    // with a clear message instead of an uncaught 403.
    if (e.response?.status === 403) {
      toastError('You are not part of this conversation.')
      router.replace({ name: 'chat' })
      return
    }
    throw e
  }
  scrollToBottom()
}

function scrollToBottom() {
  nextTick(() => {
    const el = scroller.value
    if (el) el.scrollTop = el.scrollHeight
  })
}

watch(() => route.params.id, load, { immediate: true })
watch(() => store.messages.length, scrollToBottom)
onMounted(() => store.fetchContacts())

async function sendText(body) {
  await store.sendText(body)
  scrollToBottom()
}

async function sendFile({ file, durationMs }) {
  await store.sendAttachment(file, durationMs)
  scrollToBottom()
}

async function remove(message) {
  if (
    await confirmAction({
      title: 'Delete this message?',
      text: 'It will show as deleted for everyone.',
      confirmText: 'Delete',
      danger: true,
    })
  ) {
    store.deleteMessage(message.id)
  }
}

async function addMember(userId) {
  await store.addParticipants(store.activeId, [userId])
}

async function kick(userId) {
  await store.removeParticipant(store.activeId, userId)
}

async function leave() {
  if (!(await confirmAction({ title: 'Leave this group?', confirmText: 'Leave', danger: true })))
    return
  await store.removeParticipant(store.activeId, auth.user.id)
  router.push('/chat')
}

onBeforeUnmount(() => store.unsubscribe())
</script>

<template>
  <div
    class="flex flex-col overflow-hidden bg-card"
    :class="
      nativePhone
        ? 'h-[calc(100dvh-4rem)]'
        : 'h-[calc(100vh-10.5rem)] rounded-xl border border-line shadow-card lg:h-[calc(100vh-7.5rem)]'
    "
  >
    <!-- Thread header -->
    <div class="flex items-center gap-2 border-b border-line px-3 py-2.5 sm:px-4">
      <RouterLink
        to="/chat"
        class="flex min-h-[40px] min-w-[40px] items-center justify-center rounded-lg text-mute hover:bg-surface-100 hover:text-ink dark:hover:bg-surface-800"
        aria-label="Back to inbox"
      >
        <i class="pi pi-arrow-left" aria-hidden="true" />
      </RouterLink>
      <span class="relative shrink-0">
        <Avatar
          :image="(isNative && otherUser?.avatar_url) || undefined"
          :label="isNative && otherUser?.avatar_url ? undefined : initials(store.active?.title ?? 'C')"
          shape="circle"
          class="!bg-highlight !text-primary-700 dark:!text-primary-300"
        />
        <span
          v-if="isNative && otherOnline"
          class="absolute bottom-0 right-0 h-3 w-3 rounded-full border-2 border-card bg-green-500"
          aria-label="Online"
        />
      </span>
      <div class="min-w-0 flex-1">
        <h1 class="truncate text-base font-semibold text-ink">
          <i
            v-if="store.active?.type === 'project'"
            class="pi pi-folder mr-1 text-sm text-mute"
            title="Project chat — participants follow the project's contributors"
            aria-hidden="true"
          />
          {{ store.active?.title ?? 'Conversation' }}
        </h1>
        <!-- Presence line (shell): Active now / member count, Messenger-style. -->
        <p
          v-if="isNative && store.active"
          class="truncate text-[11px]"
          :class="otherOnline ? 'text-green-600 dark:text-green-400' : 'text-mute'"
        >
          {{
            isGroup || store.active?.type === 'project'
              ? `${store.active?.participants?.length ?? 0} members`
              : otherOnline
                ? 'Active now'
                : 'Away'
          }}
        </p>
      </div>
      <!-- A project thread deep-links back to its project workspace. -->
      <RouterLink v-if="store.active?.project_link" :to="store.active.project_link">
        <Button label="Open project" icon="pi pi-folder-open" text size="small" />
      </RouterLink>
      <Button
        v-if="isGroup"
        :label="showInfo ? 'Hide info' : 'Group info'"
        icon="pi pi-users"
        text
        size="small"
        @click="showInfo = !showInfo"
      />
    </div>

    <!-- Group info / participants -->
    <div v-if="isGroup && showInfo" class="border-b border-line px-4 py-3 text-sm">
      <p class="mb-2 font-semibold text-ink">Participants</p>
      <ul class="space-y-1">
        <li
          v-for="p in store.active?.participants ?? []"
          :key="p.id"
          class="flex items-center justify-between gap-2"
        >
          <span class="flex items-center gap-2 text-ink">
            {{ p.name }}
            <Tag v-if="p.role === 'admin'" value="admin" severity="secondary" />
          </span>
          <Button
            v-if="iAmAdmin && p.id !== auth.user?.id"
            label="Remove"
            text
            size="small"
            severity="danger"
            @click="kick(p.id)"
          />
        </li>
      </ul>

      <div v-if="iAmAdmin && nonParticipants.length" class="mt-3">
        <p class="mb-1.5 font-semibold text-ink">Add member</p>
        <div class="flex flex-wrap gap-1.5">
          <button
            v-for="u in nonParticipants"
            :key="u.id"
            type="button"
            class="rounded-full border border-line px-3 py-1 text-xs text-mute transition-colors hover:border-primary hover:text-ink"
            @click="addMember(u.id)"
          >
            <i class="pi pi-plus text-[10px]" aria-hidden="true" /> {{ u.name }}
          </button>
        </div>
      </div>

      <Button
        label="Leave group"
        icon="pi pi-sign-out"
        text
        size="small"
        severity="danger"
        class="mt-3"
        @click="leave"
      />
    </div>

    <!-- Messages -->
    <div ref="scroller" class="flex-1 space-y-2 overflow-y-auto bg-ground px-3 py-4 sm:px-4">
      <p v-if="store.loadingThread" class="py-4 text-center text-sm text-mute">Loading…</p>
      <template v-for="r in rows" :key="r.key">
        <!-- Day chip (shell) -->
        <div v-if="r.kind === 'day'" class="flex justify-center py-1">
          <span
            class="rounded-full bg-surface-100 px-3 py-1 text-[11px] font-medium text-mute dark:bg-surface-800"
          >
            {{ r.label }}
          </span>
        </div>

        <div
          v-else
          class="flex items-end gap-1.5"
          :class="[
            r.m.is_mine ? 'justify-end' : 'justify-start',
            isNative && !r.groupFirst && '!mt-1',
          ]"
        >
          <!-- The author's avatar closes each run (shell, incoming side). -->
          <span v-if="isNative && !r.m.is_mine" class="w-7 shrink-0 self-end">
            <Avatar
              v-if="r.groupLast"
              :image="r.m.author?.avatar_url || undefined"
              :label="r.m.author?.avatar_url ? undefined : initials(r.m.author?.name ?? '?')"
              shape="circle"
              class="!h-7 !w-7 !bg-highlight !text-xs !text-primary-700 dark:!text-primary-300"
            />
          </span>

          <div
            class="group max-w-[80%] rounded-2xl px-3.5 py-2 shadow-card native:max-w-[85%] native:rounded-[1.25rem] native:px-4 native:py-2.5 native:shadow-none"
            :class="
              r.m.is_mine
                ? [
                    'bg-primary text-primary-contrast',
                    !isNative || r.groupLast
                      ? 'rounded-br-md native:rounded-br-[0.4rem]'
                      : 'native:rounded-br-[1.25rem]',
                  ]
                : [
                    'border border-line bg-card text-ink',
                    !isNative || r.groupLast
                      ? 'rounded-bl-md native:rounded-bl-[0.4rem]'
                      : 'native:rounded-bl-[1.25rem]',
                  ]
            "
          >
          <p
            v-if="!r.m.is_mine && r.m.author && (!isNative || r.groupFirst)"
            class="mb-0.5 text-xs font-medium text-mute"
          >
            {{ r.m.author.name }}
          </p>

          <p v-if="r.m.redacted" class="text-sm italic opacity-70">Message deleted</p>

          <template v-else>
            <template v-for="a in r.m.attachments" :key="a.id">
              <img
                v-if="a.kind === 'image'"
                :src="a.url"
                alt="Shared image"
                class="mb-1 max-h-64 rounded-xl"
              />
              <audio v-else-if="a.kind === 'voice'" :src="a.url" controls class="mb-1 w-56" />
              <a v-else :href="a.url" target="_blank" rel="noopener" class="mb-1 block underline">
                <i class="pi pi-paperclip text-xs" aria-hidden="true" /> Download file
              </a>
            </template>

            <!-- Shared-record card (RBAC-gated server-side). A live-broadcast share
                 carries subject_type but no per-user card, so fall back to generic. -->
            <div
              v-if="r.m.subject || r.m.subject_type"
              class="mb-1 rounded-lg border border-line bg-ground p-2.5 text-sm text-ink"
            >
              <span v-if="!r.m.subject || r.m.subject.restricted" class="text-mute">
                <i class="pi pi-lock text-xs" aria-hidden="true" /> A record was shared
              </span>
              <RouterLink
                v-else
                :to="r.m.subject.link"
                class="flex items-center gap-2 font-medium text-primary-600 hover:underline dark:text-primary-400"
              >
                <i class="pi pi-file" aria-hidden="true" /> {{ r.m.subject.label }}
              </RouterLink>
            </div>

            <p
              v-if="r.m.body"
              class="whitespace-pre-wrap break-words text-sm native:text-[15px] native:leading-snug"
            >
              {{ r.m.body }}
            </p>
          </template>

          <!-- Shell: clock time once per run, Messenger-style; web stays clean. -->
          <p
            v-if="isNative && !r.m.redacted && r.groupLast"
            class="num mt-0.5 text-right text-[10px] opacity-60"
          >
            {{ formatTime(r.m.created_at) }}
          </p>

          <button
            v-if="r.m.is_mine && !r.m.redacted"
            type="button"
            class="mt-1 hidden text-[10px] opacity-70 hover:opacity-100 group-hover:inline native:inline"
            @click="remove(r.m)"
          >
            Delete
          </button>
          </div>
        </div>
      </template>
      <p
        v-if="!store.loadingThread && !store.messages.length"
        class="py-8 text-center text-sm text-mute"
      >
        No messages yet. Say hello.
      </p>
    </div>

    <div
      class="border-t border-line px-3 py-2 native:max-md:pb-[max(0.5rem,env(safe-area-inset-bottom))] sm:px-4"
    >
      <p v-if="!canPost" class="flex items-center gap-2 py-1.5 text-sm text-mute">
        <i class="pi pi-eye" aria-hidden="true" />
        Oversight view — you can read this project chat but not write. Join the project as a
        contributor to take part.
      </p>
      <MessageComposer
        v-else
        :disabled="store.sending"
        @send-text="sendText"
        @send-file="sendFile"
      />
    </div>
  </div>
</template>
