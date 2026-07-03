<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Avatar from 'primevue/avatar'
import Badge from 'primevue/badge'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Dialog from 'primevue/dialog'
import BaseInput from '@/components/base/BaseInput.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useChatStore } from '@/features/collaboration/chatStore'
import { initials, timeAgo } from '@/utils/format'

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
</script>

<template>
  <div>
    <PageHeader title="Chat" subtitle="Your conversations with the team.">
      <template #actions>
        <Button label="New conversation" icon="pi pi-plus" @click="modalOpen = true" />
      </template>
    </PageHeader>

    <SectionCard flush>
      <p v-if="store.loadingList" class="py-8 text-center text-sm text-mute">Loading…</p>
      <EmptyState
        v-else-if="!store.conversations.length"
        icon="pi pi-comments"
        title="No conversations yet"
        body="Start a direct message or a group with your team."
      >
        <Button label="Start one" icon="pi pi-plus" size="small" @click="modalOpen = true" />
      </EmptyState>

      <ul v-else class="divide-y divide-line">
        <li v-for="c in store.conversations" :key="c.id">
          <button
            type="button"
            class="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-surface-50 sm:px-5 dark:hover:bg-surface-800"
            @click="open(c.id)"
          >
            <Avatar
              :label="initials(c.title ?? 'C')"
              shape="circle"
              size="large"
              class="shrink-0 !bg-highlight !text-primary-700 dark:!text-primary-300"
            />
            <span class="min-w-0 flex-1">
              <span class="flex items-center justify-between gap-2">
                <span
                  class="truncate text-sm text-ink"
                  :class="c.unread_count > 0 ? 'font-semibold' : 'font-medium'"
                >
                  {{ c.title ?? 'Conversation' }}
                </span>
                <span class="shrink-0 text-xs text-mute">{{ timeAgo(c.last_message_at) }}</span>
              </span>
              <span class="mt-0.5 block truncate text-sm text-mute">
                {{ c.last_message?.preview ?? 'No messages yet' }}
              </span>
            </span>
            <Badge v-if="c.unread_count > 0" :value="c.unread_count" />
          </button>
        </li>
      </ul>
    </SectionCard>

    <!-- New conversation -->
    <Dialog
      :visible="modalOpen"
      modal
      dismissable-mask
      class="w-[95vw] max-w-md"
      @update:visible="(v) => !v && resetModal()"
    >
      <template #header>
        <span class="flex w-full items-center justify-between gap-3 pr-2">
          <span class="font-semibold text-ink">{{ groupMode ? 'New group' : 'New message' }}</span>
          <Button
            :label="groupMode ? 'Direct message' : 'New group'"
            :icon="groupMode ? 'pi pi-user' : 'pi pi-users'"
            text
            size="small"
            @click="groupMode = !groupMode"
          />
        </span>
      </template>

      <template v-if="groupMode">
        <BaseInput v-model="groupTitle" label="Group name" />
        <div class="my-4 max-h-60 space-y-0.5 overflow-y-auto">
          <label
            v-for="u in store.contacts"
            :key="u.id"
            class="flex cursor-pointer items-center gap-2.5 rounded-lg px-2 py-1.5 text-sm text-ink hover:bg-surface-100 dark:hover:bg-surface-800"
          >
            <Checkbox v-model="groupPicks" :value="u.id" />
            {{ u.name }}
          </label>
        </div>
        <div class="flex gap-2">
          <Button
            label="Create group"
            icon="pi pi-check"
            :disabled="!groupTitle.trim() || !groupPicks.length"
            @click="createGroup"
          />
          <Button label="Cancel" severity="secondary" outlined @click="resetModal" />
        </div>
      </template>

      <template v-else>
        <ul class="max-h-72 divide-y divide-line overflow-y-auto">
          <li v-for="u in store.contacts" :key="u.id">
            <button
              type="button"
              class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left text-sm text-ink transition-colors hover:bg-surface-100 dark:hover:bg-surface-800"
              @click="startDirect(u.id)"
            >
              <Avatar
                :label="initials(u.name)"
                shape="circle"
                class="!bg-highlight !text-primary-700 dark:!text-primary-300"
              />
              {{ u.name }}
            </button>
          </li>
        </ul>
        <EmptyState
          v-if="!store.contacts.length"
          icon="pi pi-users"
          title="No contacts available"
        />
      </template>
    </Dialog>
  </div>
</template>
