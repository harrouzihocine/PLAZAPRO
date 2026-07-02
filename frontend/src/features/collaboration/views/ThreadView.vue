<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/features/settings/store'
import { useChatStore } from '@/features/collaboration/chatStore'
import MessageComposer from '@/features/collaboration/components/MessageComposer.vue'
import { confirmAction } from '@/composables/useConfirm'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const store = useChatStore()
const scroller = ref(null)
const showInfo = ref(false)

const isGroup = computed(() => store.active?.type === 'group')
const iAmAdmin = computed(
  () => store.active?.participants?.some((p) => p.id === auth.user?.id && p.role === 'admin') ?? false,
)
const nonParticipants = computed(() => {
  const ids = new Set(store.active?.participants?.map((p) => p.id) ?? [])
  return store.contacts.filter((c) => !ids.has(c.id))
})

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
  if (!(await confirmAction({ title: 'Leave this group?', confirmText: 'Leave', danger: true }))) return
  await store.removeParticipant(store.activeId, auth.user.id)
  router.push('/chat')
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
      <h1 class="flex-1 truncate text-lg font-semibold">{{ store.active?.title ?? 'Conversation' }}</h1>
      <button v-if="isGroup" class="min-h-[44px] px-2 text-sm text-primary" @click="showInfo = !showInfo">
        Group info
      </button>
    </div>

    <!-- Group info / participants -->
    <div v-if="isGroup && showInfo" class="border-b border-border p-3 text-sm">
      <p class="mb-2 font-semibold">Participants</p>
      <ul class="space-y-1">
        <li v-for="p in store.active?.participants ?? []" :key="p.id" class="flex items-center justify-between">
          <span>{{ p.name }} <span v-if="p.role === 'admin'" class="opacity-60">· admin</span></span>
          <button
            v-if="iAmAdmin && p.id !== auth.user?.id"
            class="text-danger"
            @click="kick(p.id)"
          >
            Remove
          </button>
        </li>
      </ul>

      <div v-if="iAmAdmin && nonParticipants.length" class="mt-3">
        <p class="mb-1 font-semibold">Add member</p>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="u in nonParticipants"
            :key="u.id"
            class="rounded-token border border-border px-2 py-1 hover:bg-bg"
            @click="addMember(u.id)"
          >
            + {{ u.name }}
          </button>
        </div>
      </div>

      <button class="mt-3 text-danger" @click="leave">Leave group</button>
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
              <a v-else :href="a.url" target="_blank" rel="noopener" class="mb-1 block underline">
                📎 Download file
              </a>
            </template>

            <!-- Shared-record card (RBAC-gated server-side). A live-broadcast share
                 carries subject_type but no per-user card, so fall back to generic. -->
            <div
              v-if="m.subject || m.subject_type"
              class="mb-1 rounded-token border border-border bg-bg p-2 text-sm text-ink"
            >
              <span v-if="!m.subject || m.subject.restricted" class="opacity-70">
                🔒 A record was shared
              </span>
              <RouterLink v-else :to="m.subject.link" class="flex items-center gap-2 text-primary">
                📄 {{ m.subject.label }}
              </RouterLink>
            </div>

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


    <MessageComposer :disabled="store.sending" @send-text="sendText" @send-file="sendFile" />
  </div>
</template>
