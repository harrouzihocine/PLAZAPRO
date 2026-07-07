<script setup>
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Dialog from 'primevue/dialog'
import Avatar from 'primevue/avatar'
import { useChatStore } from '@/features/collaboration/chatStore'
import { messagePreview } from '@/features/collaboration/preview'
import { toastSuccess } from '@/composables/useConfirm'
import { initials } from '@/utils/format'

// Messenger-style "Forward to…": pick one or more of my writable conversations
// and send a copy of the message there. The store call owns error toasts.
const props = defineProps({
  open: { type: Boolean, default: false },
  message: { type: Object, default: null },
})
const emit = defineEmits(['close'])

const store = useChatStore()
const picks = ref([])
const query = ref('')
const sending = ref(false)

watch(
  () => props.open,
  (v) => {
    if (v) {
      picks.value = []
      query.value = ''
      if (!store.conversations.length) store.fetchConversations().catch(() => {})
    }
  },
)

// Only threads I can post in, minus the one the message already lives in.
const candidates = computed(() => {
  const q = query.value.trim().toLowerCase()
  return store.conversations
    .filter((c) => c.can_post !== false && c.id !== props.message?.conversation_id)
    .filter((c) => !q || (c.title ?? '').toLowerCase().includes(q))
})

async function send() {
  if (!props.message || !picks.value.length || sending.value) return
  sending.value = true
  try {
    const ok = await store.forwardMessage(props.message.id, picks.value)
    if (ok) {
      toastSuccess(picks.value.length > 1 ? 'Message forwarded to the selected chats.' : 'Message forwarded.')
      emit('close')
    }
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <Dialog
    :visible="open"
    modal
    dismissable-mask
    class="w-[95vw] max-w-md"
    @update:visible="(v) => !v && emit('close')"
  >
    <template #header>
      <span class="font-semibold text-ink">Forward to…</span>
    </template>

    <p v-if="message" class="mb-3 truncate rounded-lg bg-ground px-3 py-2 text-xs text-mute">
      {{ messagePreview(message) }}
    </p>

    <input
      v-model="query"
      type="text"
      placeholder="Search chats…"
      class="mb-2 w-full rounded-xl border border-line bg-ground px-3 py-2 text-sm text-ink outline-none focus:border-primary"
    />

    <ul class="max-h-64 divide-y divide-line overflow-y-auto">
      <li v-for="c in candidates" :key="c.id">
        <label
          class="flex w-full cursor-pointer items-center gap-3 rounded-lg px-2 py-2 text-left text-sm text-ink transition-colors hover:bg-surface-100 dark:hover:bg-surface-800"
        >
          <Checkbox v-model="picks" :value="c.id" />
          <Avatar
            :label="initials(c.title ?? 'C')"
            shape="circle"
            class="!bg-highlight !text-primary-700 dark:!text-primary-300"
          />
          <span class="min-w-0 flex-1">
            <span class="block truncate">{{ c.title ?? 'Conversation' }}</span>
            <span v-if="c.type === 'project'" class="block text-[11px] text-mute">
              <i class="pi pi-folder text-[10px]" aria-hidden="true" /> Project chat
            </span>
          </span>
        </label>
      </li>
    </ul>
    <p v-if="!candidates.length" class="py-6 text-center text-sm text-mute">No chats to forward to.</p>

    <div class="mt-3 flex gap-2">
      <Button
        :label="picks.length > 1 ? `Forward (${picks.length})` : 'Forward'"
        icon="pi pi-share-alt"
        :disabled="!picks.length"
        :loading="sending"
        @click="send"
      />
      <Button label="Cancel" severity="secondary" outlined @click="emit('close')" />
    </div>
  </Dialog>
</template>
