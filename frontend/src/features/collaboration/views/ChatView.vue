<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Avatar from 'primevue/avatar'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Dialog from 'primevue/dialog'
import BaseInput from '@/components/base/BaseInput.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ConversationList from '@/features/collaboration/components/ConversationList.vue'
import ThreadPane from '@/features/collaboration/components/ThreadPane.vue'
import { useChatStore } from '@/features/collaboration/chatStore'
import { useIsPhone, useNativePhone } from '@/composables/useNativeMode'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { isNativeApp } from '@/utils/nativeApp'
import { initials } from '@/utils/format'

// The chat surface, one view for both routes (/chat and /chat/:id):
// • native tablet → WhatsApp-style TWO-PANE (inbox beside the open thread);
// • native phone  → inbox page ↔ full-bleed thread (AppShell chatTakeover);
// • web           → the classic list page ↔ thread card, same components.
const route = useRoute()
const router = useRouter()
const store = useChatStore()

const isNative = isNativeApp()
const isPhone = useIsPhone()
const nativePhone = useNativePhone()
const twoPane = computed(() => isNative && !isPhone.value)

const selectedId = computed(() => (route.params.id ? Number(route.params.id) : null))

onMounted(() => {
  if (!store.conversations.length) store.fetchConversations()
  if (!store.contacts.length) store.fetchContacts()
})
// pull-to-refresh (APK): reload the inbox, and the open thread if any. One
// component instance backs BOTH routes — register under both names.
useRefreshable(async () => {
  await store.fetchConversations()
  if (selectedId.value) await store.loadThread(selectedId.value)
}, ['chat', 'chat.thread'])

function select(id) {
  if (id === selectedId.value) return
  // Two-pane: replace so Back leaves chat instead of unwinding every selection.
  if (twoPane.value) router.replace(`/chat/${id}`)
  else router.push(`/chat/${id}`)
}

function back() {
  router.push('/chat')
}

// ── New conversation (direct / group) ──
const modalOpen = ref(false)
const groupMode = ref(false)
const groupTitle = ref('')
const groupPicks = ref([])

async function startDirect(userId) {
  const id = await store.startDirect(userId)
  resetModal()
  select(id)
}

async function createGroup() {
  if (!groupTitle.value.trim() || groupPicks.value.length === 0) return
  const id = await store.createGroup(groupTitle.value.trim(), groupPicks.value)
  resetModal()
  select(id)
}

function resetModal() {
  modalOpen.value = false
  groupMode.value = false
  groupTitle.value = ''
  groupPicks.value = []
}
</script>

<template>
  <!-- Native tablet: two-pane (inbox | thread), WhatsApp-style -->
  <div
    v-if="twoPane"
    class="flex h-[calc(100dvh-11.5rem)] min-h-[24rem] overflow-hidden rounded-xl border border-line bg-card shadow-card lg:h-[calc(100vh-7.5rem)]"
  >
    <div class="flex w-[21rem] shrink-0 flex-col border-r border-line xl:w-[24rem]">
      <div class="flex items-center justify-between px-4 pb-1 pt-3">
        <h1 class="text-lg font-bold text-ink">Chats</h1>
        <Button
          v-tooltip.bottom="'New conversation'"
          icon="pi pi-pen-to-square"
          rounded
          text
          aria-label="New conversation"
          @click="modalOpen = true"
        />
      </div>
      <ConversationList :selected-id="selectedId" @select="select" />
    </div>

    <ThreadPane
      v-if="selectedId"
      :key="selectedId"
      :conversation-id="selectedId"
      :show-back="false"
      @back="back"
    />
    <div v-else class="flex flex-1 flex-col items-center justify-center gap-3 bg-ground text-mute">
      <span
        class="flex h-20 w-20 items-center justify-center rounded-full bg-surface-100 dark:bg-surface-800"
      >
        <i class="pi pi-comments text-3xl" aria-hidden="true" />
      </span>
      <p class="text-sm font-medium">Select a conversation</p>
      <p class="max-w-[26ch] text-center text-xs">
        Pick a chat on the left, or start a new one with the pen button.
      </p>
    </div>
  </div>

  <!-- Phone (native) thread: full-bleed takeover; web thread: the classic card -->
  <div
    v-else-if="selectedId"
    class="flex flex-col overflow-hidden bg-card"
    :class="
      nativePhone
        ? 'h-[calc(100dvh-4rem)]'
        : 'h-[calc(100dvh-10.5rem)] rounded-xl border border-line shadow-card lg:h-[calc(100dvh-7.5rem)]'
    "
  >
    <ThreadPane :key="selectedId" :conversation-id="selectedId" show-back @back="back" />
  </div>

  <!-- Inbox page (phone + web) -->
  <div v-else>
    <PageHeader v-if="!isNative" title="Chat" subtitle="Your conversations with the team.">
      <template #actions>
        <Button label="New conversation" icon="pi pi-plus" @click="modalOpen = true" />
      </template>
    </PageHeader>
    <h1 v-else class="mb-2 px-1 text-2xl font-bold text-ink">Chats</h1>

    <SectionCard flush>
      <ConversationList :selected-id="selectedId" @select="select" />
    </SectionCard>

    <!-- Native FAB: new chat, WhatsApp-style -->
    <button
      v-if="isNative"
      type="button"
      class="fixed bottom-24 right-4 z-30 flex h-14 w-14 items-center justify-center rounded-full bg-primary text-primary-contrast shadow-pop transition-transform active:scale-95 lg:bottom-8"
      aria-label="New conversation"
      @click="modalOpen = true"
    >
      <i class="pi pi-pen-to-square text-xl" aria-hidden="true" />
    </button>
  </div>

  <!-- New conversation (bottom sheet on native via native.css) -->
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
      <BaseInput v-model="groupTitle" label="Group name" required />
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
      <EmptyState v-if="!store.contacts.length" icon="pi pi-users" title="No contacts available" />
    </template>
  </Dialog>
</template>
