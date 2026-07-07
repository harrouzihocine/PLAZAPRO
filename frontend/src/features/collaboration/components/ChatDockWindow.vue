<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import Avatar from 'primevue/avatar'
import { useChatStore } from '@/features/collaboration/chatStore'
import { useChatDockStore } from '@/features/collaboration/chatDockStore'
import MessageComposer from '@/features/collaboration/components/MessageComposer.vue'
import MessageList from '@/features/collaboration/components/MessageList.vue'
import { toastError } from '@/composables/useConfirm'
import { initials } from '@/utils/format'

// One popped-open thread in the dock. Messages live in the chat store's
// per-conversation map (shared with the /chat page — one refcounted Echo
// channel per thread), so several windows chat side by side without owning
// their own copies. Group administration stays on the full /chat page.
const props = defineProps({ conversationId: { type: Number, required: true } })

const chat = useChatStore()
const dock = useChatDockStore()
const loading = ref(false)
const replyTo = ref(null)

const convo = computed(() => chat.conversation(props.conversationId))
const canPost = computed(() => convo.value?.can_post ?? true)
const thread = computed(() => chat.thread(props.conversationId))

onMounted(async () => {
  loading.value = true
  chat.retain(props.conversationId)
  try {
    await chat.ensureConversation(props.conversationId)
    await chat.loadThread(props.conversationId)
    chat.markRead(props.conversationId)
  } catch {
    toastError('Could not open this conversation.')
    dock.close(props.conversationId)
    return
  } finally {
    loading.value = false
  }
})

onBeforeUnmount(() => chat.release(props.conversationId))

// A visible window keeps its thread read as messages stream in (the store only
// does this for the /chat page's active thread).
watch(
  () => thread.value.messages.length,
  () => {
    if (!dock.suspended) chat.markRead(props.conversationId)
  },
)

function sendText(body) {
  chat.sendText(props.conversationId, body, replyTo.value)
  replyTo.value = null
}

function sendFile({ file, durationMs }) {
  chat.sendAttachment(props.conversationId, file, durationMs, replyTo.value)
  replyTo.value = null
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

    <!-- Messages (shared list, compact) -->
    <p v-if="loading" class="flex-1 bg-ground py-4 text-center text-sm text-mute">Loading…</p>
    <MessageList v-else :conversation-id="conversationId" compact @reply="replyTo = $event" />

    <!-- Composer -->
    <footer class="border-t border-line px-2 py-1.5">
      <p v-if="!canPost" class="flex items-center gap-2 px-1 py-1.5 text-xs text-mute">
        <i class="pi pi-eye" aria-hidden="true" /> Read-only — you can't post in this thread.
      </p>
      <MessageComposer
        v-else
        :disabled="chat.sending"
        :reply-to="replyTo"
        @send-text="sendText"
        @send-file="sendFile"
        @cancel-reply="replyTo = null"
        @typing="chat.sendTyping(conversationId)"
      />
    </footer>
  </section>
</template>
