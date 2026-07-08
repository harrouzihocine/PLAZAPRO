<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import Avatar from 'primevue/avatar'
import Button from 'primevue/button'
import { useChatStore } from '@/features/collaboration/chatStore'
import { usePresenceStore } from '@/features/collaboration/presenceStore'
import { useAuthStore } from '@/features/settings/store'
import { isNativeApp } from '@/utils/nativeApp'
import { initials } from '@/utils/format'
import { t } from '@/i18n'

// The conversation header: back, avatar + presence, title, live status line
// (typing… beats Active now), mute bell, project deep-link, group info toggle.
const props = defineProps({
  conversationId: { type: Number, required: true },
  showBack: { type: Boolean, default: true },
  infoOpen: { type: Boolean, default: false },
})
const emit = defineEmits(['back', 'toggle-info', 'delete-conversation'])

const store = useChatStore()
const auth = useAuthStore()
const presence = usePresenceStore()
const isNative = isNativeApp()

const convo = computed(() => store.conversation(props.conversationId))
const isGroup = computed(() => convo.value?.type === 'group')
const otherUser = computed(() =>
  convo.value?.type === 'direct'
    ? (convo.value?.participants?.find((p) => p.id !== auth.user?.id) ?? null)
    : null,
)
const otherOnline = computed(() => !!otherUser.value && presence.isOnline(otherUser.value.id))
const typing = computed(() => store.typingIn(props.conversationId))

const statusLine = computed(() => {
  if (typing.value.length === 1) return `${typing.value[0].name} is typing…`
  if (typing.value.length > 1) return t('chat.severalTyping')
  if (isGroup.value || convo.value?.type === 'project') {
    return `${convo.value?.participants?.length ?? 0} members`
  }
  return otherOnline.value ? t('chat.activeNow') : t('chat.away')
})

async function toggleMute() {
  await store.toggleMute(props.conversationId)
}
</script>

<template>
  <div class="flex items-center gap-2 border-b border-line px-3 py-2.5 sm:px-4">
    <button
      v-if="showBack"
      type="button"
      class="flex min-h-[40px] min-w-[40px] items-center justify-center rounded-lg text-mute hover:bg-surface-100 hover:text-ink dark:hover:bg-surface-800"
      :aria-label="$t('chat.backToInbox')"
      @click="emit('back')"
    >
      <i class="pi pi-arrow-left" aria-hidden="true" />
    </button>

    <span class="relative shrink-0">
      <Avatar
        :image="(isNative && otherUser?.avatar_url) || undefined"
        :label="isNative && otherUser?.avatar_url ? undefined : initials(convo?.title ?? 'C')"
        shape="circle"
        class="!bg-highlight !text-primary-700 dark:!text-primary-300"
      />
      <span
        v-if="isNative && otherOnline"
        class="absolute bottom-0 end-0 h-3 w-3 rounded-full border-2 border-card bg-green-500"
        :aria-label="$t('chat.online')"
      />
    </span>

    <div class="min-w-0 flex-1">
      <h1 class="truncate text-base font-semibold text-ink">
        <i
          v-if="convo?.type === 'project'"
          class="pi pi-folder me-1 text-sm text-mute"
          :title="$t('chat.projectChatTooltip')"
          aria-hidden="true"
        />
        {{ convo?.title ?? $t('chat.conversation') }}
      </h1>
      <p
        v-if="convo"
        class="truncate text-[11px]"
        :class="
          typing.length
            ? 'font-medium italic text-primary-600 dark:text-primary-400'
            : otherOnline
              ? 'text-green-600 dark:text-green-400'
              : 'text-mute'
        "
      >
        {{ statusLine }}
      </p>
    </div>

    <button
      v-if="convo"
      v-tooltip.bottom="convo.is_muted ? $t('chat.unmute') : $t('chat.mute')"
      type="button"
      class="flex h-10 w-10 items-center justify-center rounded-full text-mute transition-colors hover:bg-surface-100 hover:text-ink dark:hover:bg-surface-800"
      :aria-label="convo.is_muted ? $t('chat.unmuteAria') : $t('chat.muteAria')"
      @click="toggleMute"
    >
      <i :class="convo.is_muted ? 'pi pi-bell-slash text-danger' : 'pi pi-bell'" aria-hidden="true" />
    </button>

    <!-- Messenger-style delete — direct/group chats only; a project chat
         follows its project and cannot be deleted. -->
    <button
      v-if="convo && convo.type !== 'project'"
      v-tooltip.bottom="$t('chat.deleteConversation')"
      type="button"
      class="flex h-10 w-10 items-center justify-center rounded-full text-mute transition-colors hover:bg-surface-100 hover:text-danger dark:hover:bg-surface-800"
      :aria-label="$t('chat.deleteConversation')"
      @click="emit('delete-conversation')"
    >
      <i class="pi pi-trash" aria-hidden="true" />
    </button>

    <!-- A project thread deep-links back to its project workspace. -->
    <RouterLink
      v-if="convo?.project_link"
      v-tooltip.bottom="$t('dispatch.openProject')"
      :to="convo.project_link"
      class="flex h-10 w-10 items-center justify-center rounded-full text-mute transition-colors hover:bg-surface-100 hover:text-ink dark:hover:bg-surface-800"
      :aria-label="$t('chat.openProjectAria')"
    >
      <i class="pi pi-folder-open" aria-hidden="true" />
    </RouterLink>

    <Button
      v-if="isGroup"
      v-tooltip.bottom="infoOpen ? $t('chat.hideInfo') : $t('chat.groupInfo')"
      icon="pi pi-users"
      text
      rounded
      :aria-label="infoOpen ? $t('chat.hideInfo') : $t('chat.groupInfo')"
      @click="emit('toggle-info')"
    />
  </div>
</template>
