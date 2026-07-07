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
import { isNativeApp } from '@/utils/nativeApp'
import { formatTime, initials } from '@/utils/format'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const store = useChatStore()
const scroller = ref(null)
const showInfo = ref(false)

// Android shell: on phones the thread takes over the viewport edge-to-edge
// (the AppShell drops its padding and bottom bar on this route); on every
// native size the bubbles get the messaging-app treatment (timestamps,
// always-visible delete — there is no hover on touch).
const isNative = isNativeApp()
const nativePhone = useNativePhone()

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
      <Avatar
        :label="initials(store.active?.title ?? 'C')"
        shape="circle"
        class="!bg-highlight !text-primary-700 dark:!text-primary-300"
      />
      <h1 class="min-w-0 flex-1 truncate text-base font-semibold text-ink">
        <i
          v-if="store.active?.type === 'project'"
          class="pi pi-folder mr-1 text-sm text-mute"
          title="Project chat — participants follow the project's contributors"
          aria-hidden="true"
        />
        {{ store.active?.title ?? 'Conversation' }}
      </h1>
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
      <div
        v-for="m in store.messages"
        :key="m.id"
        class="flex"
        :class="m.is_mine ? 'justify-end' : 'justify-start'"
      >
        <div
          class="group max-w-[80%] rounded-2xl px-3.5 py-2 shadow-card native:max-w-[85%] native:rounded-[1.25rem] native:px-4 native:py-2.5 native:shadow-none"
          :class="
            m.is_mine
              ? 'rounded-br-md bg-primary text-primary-contrast native:rounded-br-[0.4rem]'
              : 'rounded-bl-md border border-line bg-card text-ink native:rounded-bl-[0.4rem]'
          "
        >
          <p v-if="!m.is_mine && m.author" class="mb-0.5 text-xs font-medium text-mute">
            {{ m.author.name }}
          </p>

          <p v-if="m.redacted" class="text-sm italic opacity-70">Message deleted</p>

          <template v-else>
            <template v-for="a in m.attachments" :key="a.id">
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
              v-if="m.subject || m.subject_type"
              class="mb-1 rounded-lg border border-line bg-ground p-2.5 text-sm text-ink"
            >
              <span v-if="!m.subject || m.subject.restricted" class="text-mute">
                <i class="pi pi-lock text-xs" aria-hidden="true" /> A record was shared
              </span>
              <RouterLink
                v-else
                :to="m.subject.link"
                class="flex items-center gap-2 font-medium text-primary-600 hover:underline dark:text-primary-400"
              >
                <i class="pi pi-file" aria-hidden="true" /> {{ m.subject.label }}
              </RouterLink>
            </div>

            <p
              v-if="m.body"
              class="whitespace-pre-wrap break-words text-sm native:text-[15px] native:leading-snug"
            >
              {{ m.body }}
            </p>
          </template>

          <!-- Native: clock time like every messaging app; web keeps its clean look. -->
          <p v-if="isNative && !m.redacted" class="num mt-0.5 text-right text-[10px] opacity-60">
            {{ formatTime(m.created_at) }}
          </p>

          <button
            v-if="m.is_mine && !m.redacted"
            type="button"
            class="mt-1 hidden text-[10px] opacity-70 hover:opacity-100 group-hover:inline native:inline"
            @click="remove(m)"
          >
            Delete
          </button>
        </div>
      </div>
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
