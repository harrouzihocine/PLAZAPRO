<script setup>
import { ref } from 'vue'
import { useChatStore } from '@/features/collaboration/chatStore'

// A small "Share to chat" affordance for any record (client/deal/unit). Opens a
// picker of the user's conversations and shares the record into the chosen one.
// Server-side RBAC still governs who can actually see the shared card.
const props = defineProps({
  subjectType: { type: String, required: true },
  subjectId: { type: [String, Number], required: true },
  label: { type: String, default: 'Share to chat' },
})

const store = useChatStore()
const open = ref(false)
const shared = ref(false)
const busy = ref(false)

async function openPicker() {
  shared.value = false
  open.value = true
  if (!store.conversations.length) await store.fetchConversations()
}

async function shareTo(conversationId) {
  busy.value = true
  try {
    await store.shareRecord(conversationId, props.subjectType, Number(props.subjectId))
    shared.value = true
    open.value = false
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <span>
    <button class="text-sm text-primary hover:underline" @click="openPicker">💬 {{ label }}</button>
    <span v-if="shared" class="ml-2 text-sm text-success">Shared ✓</span>

    <div
      v-if="open"
      class="fixed inset-0 z-40 flex items-end justify-center bg-black/40 sm:items-center"
      @click.self="open = false"
    >
      <div class="w-full max-w-md rounded-token bg-bg p-4 shadow-lg sm:p-6">
        <h2 class="mb-3 text-lg font-semibold">Share to a conversation</h2>
        <ul class="max-h-72 divide-y divide-border overflow-y-auto">
          <li v-for="c in store.conversations" :key="c.id">
            <button
              class="w-full py-2 text-left hover:bg-surface disabled:opacity-50"
              :disabled="busy"
              @click="shareTo(c.id)"
            >
              {{ c.title ?? 'Conversation' }}
            </button>
          </li>
          <li v-if="!store.conversations.length" class="py-4 text-center text-sm opacity-60">
            No conversations yet. Open Chat and start one first.
          </li>
        </ul>
        <button class="mt-3 text-sm opacity-70" @click="open = false">Cancel</button>
      </div>
    </div>
  </span>
</template>
