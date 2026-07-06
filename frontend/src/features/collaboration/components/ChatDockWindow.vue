<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import Avatar from 'primevue/avatar'
import { chatApi } from '@/features/collaboration/api'
import { useChatStore } from '@/features/collaboration/chatStore'
import { useChatDockStore } from '@/features/collaboration/chatDockStore'
import MessageComposer from '@/features/collaboration/components/MessageComposer.vue'
import { getEcho } from '@/composables/useEcho'
import { toastError } from '@/composables/useConfirm'
import { initials } from '@/utils/format'

// One popped-open thread in the dock. Deliberately self-contained — it owns its
// messages and its own live subscription (unlike ThreadView, which drives the
// chat store's single active thread), so several windows can chat side by side.
// Group administration and message deletion stay on the full /chat page (the
// expand button in the header leads there).
const props = defineProps({ conversationId: { type: Number, required: true } })

const chat = useChatStore()
const dock = useChatDockStore()
const messages = ref([])
const loading = ref(false)
const sending = ref(false)
const scroller = ref(null)

// Header meta + composer lock come from the inbox row (fetched if missing).
const convo = computed(() => chat.conversations.find((c) => c.id === props.conversationId))
const canPost = computed(() => convo.value?.can_post ?? true)

onMounted(async () => {
  loading.value = true
  try {
    if (!convo.value) {
      chat.conversations.unshift(await chatApi.conversation(props.conversationId))
    }
    messages.value = await chatApi.messages(props.conversationId)
    markRead()
    getEcho()
      ?.private(`conversation.${props.conversationId}`)
      .listen('.message.sent', (payload) => {
        if (!messages.value.some((m) => m.id === payload.id)) messages.value.push(payload)
        markRead()
      })
  } catch {
    toastError('Could not open this conversation.')
    dock.close(props.conversationId)
    return
  } finally {
    loading.value = false
  }
  scrollToBottom()
})

onBeforeUnmount(() => getEcho()?.leave(`conversation.${props.conversationId}`))

function markRead() {
  chatApi.markRead(props.conversationId)
  if (convo.value) convo.value.unread_count = 0
}

function scrollToBottom() {
  nextTick(() => {
    const el = scroller.value
    if (el) el.scrollTop = el.scrollHeight
  })
}

watch(() => messages.value.length, scrollToBottom)

async function send(form) {
  sending.value = true
  try {
    const message = await chatApi.sendMessage(props.conversationId, form)
    if (!messages.value.some((m) => m.id === message.id)) messages.value.push(message)
  } catch (e) {
    toastError(e.response?.data?.message ?? 'Could not send the message.')
  } finally {
    sending.value = false
  }
  scrollToBottom()
}

async function sendText(body) {
  if (!body.trim()) return
  const form = new FormData()
  form.append('body', body.trim())
  await send(form)
}

async function sendFile({ file, durationMs }) {
  if (!file) return
  const form = new FormData()
  form.append('attachment', file)
  if (durationMs != null) form.append('duration_ms', String(Math.round(durationMs)))
  await send(form)
}
</script>

<template>
  <section
    class="flex h-[26rem] w-80 max-w-[calc(100vw-1.5rem)] flex-col overflow-hidden rounded-xl border border-line bg-card shadow-card sm:w-[22rem]"
    aria-label="Chat window"
  >
    <!-- Header -->
    <header class="flex items-center gap-2 border-b border-line px-3 py-2">
      <Avatar
        :label="initials(convo?.title ?? 'C')"
        shape="circle"
        size="small"
        class="!bg-highlight !text-primary-700 dark:!text-primary-300"
      />
      <p class="min-w-0 flex-1 truncate text-sm font-semibold text-ink">
        {{ convo?.title ?? 'Conversation' }}
      </p>
      <RouterLink
        v-tooltip.top="'Open in Chat'"
        :to="`/chat/${conversationId}`"
        class="flex h-8 w-8 items-center justify-center rounded-full text-mute transition-colors hover:bg-surface-100 hover:text-ink dark:hover:bg-surface-800"
        aria-label="Open the full chat page"
      >
        <i class="pi pi-window-maximize text-xs" aria-hidden="true" />
      </RouterLink>
      <button
        type="button"
        class="flex h-8 w-8 items-center justify-center rounded-full text-mute transition-colors hover:bg-surface-100 hover:text-ink dark:hover:bg-surface-800"
        aria-label="Close chat window"
        @click="dock.close(conversationId)"
      >
        <i class="pi pi-times text-xs" aria-hidden="true" />
      </button>
    </header>

    <!-- Messages -->
    <div ref="scroller" class="flex-1 space-y-2 overflow-y-auto bg-ground px-3 py-3">
      <p v-if="loading" class="py-4 text-center text-sm text-mute">Loading…</p>
      <div
        v-for="m in messages"
        :key="m.id"
        class="flex"
        :class="m.is_mine ? 'justify-end' : 'justify-start'"
      >
        <div
          class="max-w-[85%] rounded-2xl px-3 py-1.5 shadow-card"
          :class="
            m.is_mine
              ? 'rounded-br-md bg-primary text-primary-contrast'
              : 'rounded-bl-md border border-line bg-card text-ink'
          "
        >
          <p v-if="!m.is_mine && m.author" class="mb-0.5 text-[11px] font-medium text-mute">
            {{ m.author.name }}
          </p>

          <p v-if="m.redacted" class="text-sm italic opacity-70">Message deleted</p>

          <template v-else>
            <template v-for="a in m.attachments" :key="a.id">
              <img
                v-if="a.kind === 'image'"
                :src="a.url"
                alt="Shared image"
                class="mb-1 max-h-40 rounded-lg"
              />
              <audio v-else-if="a.kind === 'voice'" :src="a.url" controls class="mb-1 w-48" />
              <a v-else :href="a.url" target="_blank" rel="noopener" class="mb-1 block underline">
                <i class="pi pi-paperclip text-xs" aria-hidden="true" /> Download file
              </a>
            </template>

            <!-- Shared-record card (RBAC-gated server-side). -->
            <div
              v-if="m.subject || m.subject_type"
              class="mb-1 rounded-lg border border-line bg-ground p-2 text-sm text-ink"
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

            <p v-if="m.body" class="whitespace-pre-wrap break-words text-sm">{{ m.body }}</p>
          </template>
        </div>
      </div>
      <p v-if="!loading && !messages.length" class="py-8 text-center text-sm text-mute">
        No messages yet. Say hello.
      </p>
    </div>

    <!-- Composer -->
    <footer class="border-t border-line px-2 py-1.5">
      <p v-if="!canPost" class="flex items-center gap-2 px-1 py-1.5 text-xs text-mute">
        <i class="pi pi-eye" aria-hidden="true" /> Read-only — you can't post in this thread.
      </p>
      <MessageComposer v-else :disabled="sending" @send-text="sendText" @send-file="sendFile" />
    </footer>
  </section>
</template>
