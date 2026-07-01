<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import { useChatStore } from '@/features/collaboration/chatStore'

const store = useChatStore()
const router = useRouter()

const modalOpen = ref(false)
const groupMode = ref(false)
const groupTitle = ref('')
const groupPicks = ref([])

onMounted(() => {
  store.fetchConversations()
  store.fetchContacts()
})

function open(id) {
  router.push(`/chat/${id}`)
}

async function startDirect(userId) {
  const id = await store.startDirect(userId)
  modalOpen.value = false
  open(id)
}

async function createGroup() {
  if (!groupTitle.value.trim() || groupPicks.value.length === 0) return
  const id = await store.createGroup(groupTitle.value.trim(), groupPicks.value)
  resetModal()
  open(id)
}

function resetModal() {
  modalOpen.value = false
  groupMode.value = false
  groupTitle.value = ''
  groupPicks.value = []
}

function formatTime(value) {
  if (!value) return ''
  return new Date(value).toLocaleDateString(undefined, { day: '2-digit', month: 'short' })
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold">Chat</h1>
        <p class="opacity-70">Your conversations.</p>
      </div>
      <BaseButton @click="modalOpen = true">New</BaseButton>
    </div>

    <BaseCard>
      <p v-if="store.loadingList" class="py-4 text-center text-sm opacity-60">Loading…</p>
      <ul v-else class="divide-y divide-border">
        <li v-for="c in store.conversations" :key="c.id">
          <button class="flex w-full items-center gap-3 py-3 text-left hover:bg-bg" @click="open(c.id)">
            <div class="min-w-0 flex-1">
              <div class="flex items-center justify-between gap-2">
                <span class="truncate font-medium">{{ c.title ?? 'Conversation' }}</span>
                <span class="shrink-0 text-xs opacity-60">{{ formatTime(c.last_message_at) }}</span>
              </div>
              <p class="truncate text-sm opacity-70">{{ c.last_message?.preview ?? 'No messages yet' }}</p>
            </div>
            <span
              v-if="c.unread_count > 0"
              class="min-w-[20px] rounded-full bg-primary px-1.5 text-center text-xs text-on-primary"
            >
              {{ c.unread_count }}
            </span>
          </button>
        </li>
        <li v-if="!store.conversations.length" class="py-8 text-center text-sm opacity-60">
          No conversations yet. Start one.
        </li>
      </ul>
    </BaseCard>

    <!-- New conversation modal -->
    <div
      v-if="modalOpen"
      class="fixed inset-0 z-40 flex items-end justify-center bg-black/40 sm:items-center"
      @click.self="resetModal"
    >
      <div class="w-full max-w-md rounded-token bg-bg p-4 shadow-lg sm:p-6">
        <div class="mb-3 flex items-center justify-between">
          <h2 class="text-lg font-semibold">{{ groupMode ? 'New group' : 'New message' }}</h2>
          <button class="text-sm text-primary" @click="groupMode = !groupMode">
            {{ groupMode ? 'Direct message' : 'New group' }}
          </button>
        </div>

        <template v-if="groupMode">
          <BaseInput v-model="groupTitle" label="Group name" />
          <div class="my-3 max-h-60 space-y-1 overflow-y-auto">
            <label v-for="u in store.contacts" :key="u.id" class="flex items-center gap-2 py-1">
              <input v-model="groupPicks" type="checkbox" :value="u.id" />
              <span>{{ u.name }}</span>
            </label>
          </div>
          <div class="flex gap-2">
            <BaseButton :disabled="!groupTitle.trim() || !groupPicks.length" @click="createGroup">
              Create
            </BaseButton>
            <BaseButton variant="ghost" @click="resetModal">Cancel</BaseButton>
          </div>
        </template>

        <ul v-else class="max-h-72 divide-y divide-border overflow-y-auto">
          <li v-for="u in store.contacts" :key="u.id">
            <button class="w-full py-2 text-left hover:bg-surface" @click="startDirect(u.id)">
              {{ u.name }}
            </button>
          </li>
          <li v-if="!store.contacts.length" class="py-4 text-center text-sm opacity-60">
            No contacts available.
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>
