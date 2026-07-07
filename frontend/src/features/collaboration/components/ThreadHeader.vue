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

// The conversation header: back, avatar + presence, title, live status line
// (typing… beats Active now), mute bell, project deep-link, group info toggle.
const props = defineProps({
  conversationId: { type: Number, required: true },
  showBack: { type: Boolean, default: true },
  infoOpen: { type: Boolean, default: false },
})
const emit = defineEmits(['back', 'toggle-info'])

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
  if (typing.value.length > 1) return 'Several people are typing…'
  if (isGroup.value || convo.value?.type === 'project') {
    return `${convo.value?.participants?.length ?? 0} members`
  }
  return otherOnline.value ? 'Active now' : 'Away'
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
      aria-label="Back to inbox"
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
        class="absolute bottom-0 right-0 h-3 w-3 rounded-full border-2 border-card bg-green-500"
        aria-label="Online"
      />
    </span>

    <div class="min-w-0 flex-1">
      <h1 class="truncate text-base font-semibold text-ink">
        <i
          v-if="convo?.type === 'project'"
          class="pi pi-folder mr-1 text-sm text-mute"
          title="Project chat — participants follow the project's contributors"
          aria-hidden="true"
        />
        {{ convo?.title ?? 'Conversation' }}
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
      v-tooltip.bottom="convo.is_muted ? 'Unmute' : 'Mute'"
      type="button"
      class="flex h-10 w-10 items-center justify-center rounded-full text-mute transition-colors hover:bg-surface-100 hover:text-ink dark:hover:bg-surface-800"
      :aria-label="convo.is_muted ? 'Unmute conversation' : 'Mute conversation'"
      @click="toggleMute"
    >
      <i :class="convo.is_muted ? 'pi pi-bell-slash text-danger' : 'pi pi-bell'" aria-hidden="true" />
    </button>

    <!-- A project thread deep-links back to its project workspace. -->
    <RouterLink
      v-if="convo?.project_link"
      v-tooltip.bottom="'Open project'"
      :to="convo.project_link"
      class="flex h-10 w-10 items-center justify-center rounded-full text-mute transition-colors hover:bg-surface-100 hover:text-ink dark:hover:bg-surface-800"
      aria-label="Open the project workspace"
    >
      <i class="pi pi-folder-open" aria-hidden="true" />
    </RouterLink>

    <Button
      v-if="isGroup"
      v-tooltip.bottom="infoOpen ? 'Hide info' : 'Group info'"
      icon="pi pi-users"
      text
      rounded
      :aria-label="infoOpen ? 'Hide group info' : 'Show group info'"
      @click="emit('toggle-info')"
    />
  </div>
</template>
