<script setup>
import { ref } from 'vue'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import { useChatStore } from '@/features/collaboration/chatStore'
import EmptyState from '@/components/ui/EmptyState.vue'

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
  <span class="inline-flex items-center gap-2">
    <Button
      :label="label"
      icon="pi pi-share-alt"
      size="small"
      severity="secondary"
      outlined
      @click="openPicker"
    />
    <span v-if="shared" class="text-sm text-success">
      <i class="pi pi-check" aria-hidden="true" /> Shared
    </span>

    <Dialog
      v-model:visible="open"
      modal
      dismissable-mask
      header="Share to a conversation"
      class="w-[95vw] max-w-md"
    >
      <ul class="max-h-72 divide-y divide-line overflow-y-auto">
        <li v-for="c in store.conversations" :key="c.id">
          <button
            type="button"
            class="flex w-full items-center gap-3 rounded-lg px-2 py-2.5 text-left text-sm text-ink transition-colors hover:bg-surface-100 disabled:opacity-50 dark:hover:bg-surface-800"
            :disabled="busy"
            @click="shareTo(c.id)"
          >
            <i class="pi pi-comments text-mute" aria-hidden="true" />
            {{ c.title ?? 'Conversation' }}
          </button>
        </li>
      </ul>
      <EmptyState
        v-if="!store.conversations.length"
        icon="pi pi-comments"
        title="No conversations yet"
        body="Open Chat and start one first."
      />
    </Dialog>
  </span>
</template>
