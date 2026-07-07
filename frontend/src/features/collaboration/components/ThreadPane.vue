<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import ThreadHeader from '@/features/collaboration/components/ThreadHeader.vue'
import MessageList from '@/features/collaboration/components/MessageList.vue'
import MessageComposer from '@/features/collaboration/components/MessageComposer.vue'
import { useChatStore } from '@/features/collaboration/chatStore'
import { useAuthStore } from '@/features/settings/store'
import { confirmAction, toastError } from '@/composables/useConfirm'

// One full conversation: header + message list + composer (with reply quoting
// and typing signals). Used by the phone thread page and the tablet two-pane —
// it owns the thread's lifecycle: load, live retain/release, mark-read.
const props = defineProps({
  conversationId: { type: Number, required: true },
  showBack: { type: Boolean, default: true },
})
const emit = defineEmits(['back'])

const store = useChatStore()
const auth = useAuthStore()
const router = useRouter()
const showInfo = ref(false)
const replyTo = ref(null)

const convo = computed(() => store.conversation(props.conversationId))
const isGroup = computed(() => convo.value?.type === 'group')
// Oversight readers (project chats via chat.view_project_chats) see the thread
// but cannot write — the composer locks and a notice explains why.
const canPost = computed(() => convo.value?.can_post ?? true)
const iAmAdmin = computed(
  () =>
    convo.value?.participants?.some((p) => p.id === auth.user?.id && p.role === 'admin') ?? false,
)
const nonParticipants = computed(() => {
  const ids = new Set(convo.value?.participants?.map((p) => p.id) ?? [])
  return store.contacts.filter((c) => !ids.has(c.id))
})

let retainedId = null

async function open(id) {
  if (retainedId !== null && retainedId !== id) store.release(retainedId)
  if (retainedId !== id) {
    store.retain(id)
    retainedId = id
  }
  showInfo.value = false
  replyTo.value = null
  try {
    await store.openThread(id)
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
  if (!store.contacts.length) store.fetchContacts().catch(() => {})
}

watch(() => props.conversationId, open, { immediate: true })

onBeforeUnmount(() => {
  if (retainedId !== null) store.release(retainedId)
  store.closeThread()
})

function sendText(body) {
  store.sendText(props.conversationId, body, replyTo.value)
  replyTo.value = null
}

function sendFile({ file, durationMs }) {
  store.sendAttachment(props.conversationId, file, durationMs, replyTo.value)
  replyTo.value = null
}

async function addMember(userId) {
  await store.addParticipants(props.conversationId, [userId])
}

async function kick(userId) {
  await store.removeParticipant(props.conversationId, userId)
}

async function leave() {
  if (!(await confirmAction({ title: 'Leave this group?', confirmText: 'Leave', danger: true })))
    return
  await store.removeParticipant(props.conversationId, auth.user.id)
  emit('back')
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <ThreadHeader
      :conversation-id="conversationId"
      :show-back="showBack"
      :info-open="showInfo"
      @back="emit('back')"
      @toggle-info="showInfo = !showInfo"
    />

    <!-- Group info / participants -->
    <div v-if="isGroup && showInfo" class="border-b border-line px-4 py-3 text-sm">
      <p class="mb-2 font-semibold text-ink">Participants</p>
      <ul class="space-y-1">
        <li
          v-for="p in convo?.participants ?? []"
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

    <p v-if="store.loadingThread" class="bg-ground py-4 text-center text-sm text-mute">Loading…</p>
    <MessageList v-else :conversation-id="conversationId" @reply="replyTo = $event" />

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
        :reply-to="replyTo"
        @send-text="sendText"
        @send-file="sendFile"
        @cancel-reply="replyTo = null"
        @typing="store.sendTyping(conversationId)"
      />
    </div>
  </div>
</template>
