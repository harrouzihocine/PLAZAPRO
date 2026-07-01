<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { useChatStore } from '@/features/collaboration/chatStore'
import MessageComposer from '@/features/collaboration/components/MessageComposer.vue'

const route = useRoute()
const store = useChatStore()
const scroller = ref(null)

async function load(id) {
  if (!id) return
  await store.openThread(Number(id))
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

async function sendText(body) {
  await store.sendText(body)
  scrollToBottom()
}

async function sendFile({ file, durationMs }) {
  await store.sendAttachment(file, durationMs)
  scrollToBottom()
}

function remove(message) {
  if (window.confirm('Delete this message? It will show as deleted for everyone.')) {
    store.deleteMessage(message.id)
  }
}

onBeforeUnmount(() => store.unsubscribe())
</script>

<template>
  <div class="flex h-[calc(100vh-7rem)] flex-col md:h-[calc(100vh-4.5rem)]">
    <!-- Thread header -->
    <div class="flex items-center gap-2 border-b border-border pb-2">
      <RouterLink to="/chat" class="min-h-[44px] px-2 py-2 md:hidden" aria-label="Back to inbox">
        ‹
      </RouterLink>
      <h1 class="truncate text-lg font-semibold">{{ store.active?.title ?? 'Conversation' }}</h1>
    </div>

    <!-- Messages -->
    <div ref="scroller" class="flex-1 space-y-2 overflow-y-auto py-3">
      <p v-if="store.loadingThread" class="py-4 text-center text-sm opacity-60">Loading…</p>
      <div
        v-for="m in store.messages"
        :key="m.id"
        class="flex"
        :class="m.is_mine ? 'justify-end' : 'justify-start'"
      >
        <div
          class="group max-w-[80%] rounded-token px-3 py-2"
          :class="m.is_mine ? 'bg-primary text-on-primary' : 'bg-surface border border-border'"
        >
          <p v-if="!m.is_mine && m.author" class="mb-0.5 text-xs opacity-70">{{ m.author.name }}</p>

          <p v-if="m.redacted" class="text-sm italic opacity-70">Message deleted</p>

          <template v-else>
            <template v-for="a in m.attachments" :key="a.id">
              <img
                v-if="a.kind === 'image'"
                :src="a.url"
                alt="Shared image"
                class="mb-1 max-h-64 rounded-token"
              />
              <audio v-else-if="a.kind === 'voice'" :src="a.url" controls class="mb-1 w-56" />
              <a
                v-else
                :href="a.url"
                target="_blank"
                rel="noopener"
                class="mb-1 block underline"
              >
                📎 Download file
              </a>
            </template>
            <p v-if="m.body" class="whitespace-pre-wrap break-words text-sm">{{ m.body }}</p>
          </template>

          <button
            v-if="m.is_mine && !m.redacted"
            class="mt-1 hidden text-[10px] opacity-70 group-hover:inline"
            @click="remove(m)"
          >
            Delete
          </button>
        </div>
      </div>
      <p v-if="!store.loadingThread && !store.messages.length" class="py-8 text-center text-sm opacity-60">
        No messages yet. Say hello.
      </p>
    </div>

    <p v-if="store.error" class="px-2 pb-1 text-sm text-danger">{{ store.error }}</p>

    <MessageComposer :disabled="store.sending" @send-text="sendText" @send-file="sendFile" />
  </div>
</template>
