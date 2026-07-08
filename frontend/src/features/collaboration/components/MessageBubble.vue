<script setup>
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import Avatar from 'primevue/avatar'
import ReactionPicker from '@/features/collaboration/components/ReactionPicker.vue'
import VoiceBubble from '@/features/collaboration/components/VoiceBubble.vue'
import { isNativeApp } from '@/utils/nativeApp'
import { formatTime, initials } from '@/utils/format'

// THE chat bubble — the one source of truth for the thread page, the tablet
// two-pane and the dock windows (compact). Owns the touch grammar: swipe right
// to reply, long-press for reactions/actions; web gets hover buttons instead.
const props = defineProps({
  m: { type: Object, required: true },
  groupFirst: { type: Boolean, default: true },
  groupLast: { type: Boolean, default: true },
  compact: { type: Boolean, default: false },
  canPost: { type: Boolean, default: true },
  // Every other participant's read cursor has passed this message (✓✓).
  seen: { type: Boolean, default: false },
})
const emit = defineEmits([
  'reply',
  'react',
  'delete',
  'edit',
  'forward',
  'open-media',
  'jump',
  'retry',
  'discard',
])

const isNative = isNativeApp()
const menuOpen = ref(false)
const dragX = ref(0)

const interactive = computed(
  () => props.canPost && !props.m.redacted && !props.m.pending && !props.m.failed,
)
const myReaction = computed(() => props.m.reactions?.find((g) => g.mine)?.emoji ?? null)
// Editing: my own plain-text messages only (Messenger rules).
const canEdit = computed(() => props.m.is_mine && props.m.type === 'text' && !props.m.subject_type)
// Forwarding copies body + attachments; shared-record cards stay in their thread.
const canForward = computed(() => !props.m.subject_type && props.m.type !== 'system')

// ── Swipe-to-reply (native): horizontal-intent drag with resistance ──
let startX = 0
let startY = 0
let horizontal = null
const SWIPE_TRIGGER = 48

function onTouchStart(e) {
  if (!isNative || !interactive.value || props.compact) return
  startX = e.touches[0].clientX
  startY = e.touches[0].clientY
  horizontal = null
}

function onTouchMove(e) {
  if (!isNative || !interactive.value || props.compact) return
  const dx = e.touches[0].clientX - startX
  const dy = e.touches[0].clientY - startY
  if (horizontal === null && (Math.abs(dx) > 8 || Math.abs(dy) > 8)) {
    horizontal = Math.abs(dx) > Math.abs(dy) * 2
  }
  if (!horizontal || dx <= 0) {
    dragX.value = 0
    return
  }
  e.preventDefault() // the swipe owns the gesture — stop the list scrolling
  dragX.value = Math.min(72, dx * 0.5)
}

function onTouchEnd() {
  if (dragX.value >= SWIPE_TRIGGER) emit('reply', props.m)
  dragX.value = 0
  horizontal = null
}

// ── Long-press → reaction bar + actions (native); hover buttons on web ──
let pressTimer = null
let pressX = 0
let pressY = 0

function onPressStart(e) {
  if (!interactive.value) return
  pressX = e.clientX
  pressY = e.clientY
  clearTimeout(pressTimer)
  pressTimer = setTimeout(() => {
    menuOpen.value = true
  }, 450)
}

function onPressMove(e) {
  // Only a real drag cancels the long-press — fingers always tremble a little.
  if (Math.abs(e.clientX - pressX) > 10 || Math.abs(e.clientY - pressY) > 10) {
    clearTimeout(pressTimer)
  }
}

function onPressCancel() {
  clearTimeout(pressTimer)
}

function pickReaction(emoji) {
  menuOpen.value = false
  emit('react', { message: props.m, emoji })
}

function act(action) {
  menuOpen.value = false
  if (action === 'reply') emit('reply', props.m)
  if (action === 'edit') emit('edit', props.m)
  if (action === 'forward') emit('forward', props.m)
  if (action === 'delete') emit('delete', props.m)
}
</script>

<template>
  <div
    class="flex items-end gap-1.5"
    :class="[m.is_mine ? 'justify-end' : 'justify-start', isNative && !groupFirst && '!mt-1']"
  >
    <!-- The author's avatar closes each run (native, incoming side). -->
    <span v-if="isNative && !m.is_mine && !compact" class="w-7 shrink-0 self-end">
      <Avatar
        v-if="groupLast"
        :image="m.author?.avatar_url || undefined"
        :label="m.author?.avatar_url ? undefined : initials(m.author?.name ?? '?')"
        shape="circle"
        class="!h-7 !w-7 !bg-highlight !text-xs !text-primary-700 dark:!text-primary-300"
      />
    </span>

    <div
      class="group relative min-w-0"
      :class="compact ? 'max-w-[85%]' : 'max-w-[80%] native:max-w-[85%]'"
    >
      <!-- Reaction bar / actions popover -->
      <template v-if="menuOpen">
        <div class="fixed inset-0 z-20" aria-hidden="true" @click="menuOpen = false" />
        <div
          class="absolute bottom-full z-30 mb-1.5 flex flex-col gap-1.5"
          :class="m.is_mine ? 'right-0 items-end' : 'left-0 items-start'"
        >
          <ReactionPicker :current="myReaction" @pick="pickReaction" />
          <div
            class="flex items-center gap-1 rounded-full border border-line bg-card px-1.5 py-1 shadow-pop"
          >
            <button
              type="button"
              class="flex h-8 items-center gap-1.5 rounded-full px-2.5 text-xs font-medium text-ink active:bg-highlight"
              @click.stop="act('reply')"
            >
              <i class="pi pi-reply text-[11px]" aria-hidden="true" /> Reply
            </button>
            <button
              v-if="canForward"
              type="button"
              class="flex h-8 items-center gap-1.5 rounded-full px-2.5 text-xs font-medium text-ink active:bg-highlight"
              @click.stop="act('forward')"
            >
              <i class="pi pi-share-alt text-[11px]" aria-hidden="true" /> Forward
            </button>
            <button
              v-if="canEdit"
              type="button"
              class="flex h-8 items-center gap-1.5 rounded-full px-2.5 text-xs font-medium text-ink active:bg-highlight"
              @click.stop="act('edit')"
            >
              <i class="pi pi-pencil text-[11px]" aria-hidden="true" /> Edit
            </button>
            <button
              v-if="m.is_mine"
              type="button"
              class="flex h-8 items-center gap-1.5 rounded-full px-2.5 text-xs font-medium text-danger active:bg-highlight"
              @click.stop="act('delete')"
            >
              <i class="pi pi-trash text-[11px]" aria-hidden="true" /> Delete
            </button>
          </div>
        </div>
      </template>

      <!-- The bubble -->
      <div
        class="rounded-2xl px-3.5 py-2 shadow-card transition-transform native:rounded-[1.25rem] native:px-4 native:py-2.5 native:shadow-none"
        :class="[
          m.is_mine
            ? [
                'bg-chat-own text-chat-own-contrast',
                !isNative || groupLast
                  ? 'rounded-br-md native:rounded-br-[0.4rem]'
                  : 'native:rounded-br-[1.25rem]',
              ]
            : [
                'border border-line bg-card text-ink',
                !isNative || groupLast
                  ? 'rounded-bl-md native:rounded-bl-[0.4rem]'
                  : 'native:rounded-bl-[1.25rem]',
              ],
          compact && '!px-3 !py-1.5',
        ]"
        :style="dragX ? { transform: `translateX(${dragX}px)` } : undefined"
        @touchstart="onTouchStart"
        @touchmove="onTouchMove"
        @touchend="onTouchEnd"
        @touchcancel="onTouchEnd"
        @pointerdown="onPressStart"
        @pointerup="onPressCancel"
        @pointermove="onPressMove"
        @pointerleave="onPressCancel"
        @contextmenu.prevent
      >
        <p
          v-if="!m.is_mine && m.author && (!isNative || groupFirst)"
          class="mb-0.5 text-xs font-medium"
          :class="compact ? 'text-[11px] text-mute' : 'text-mute'"
        >
          {{ m.author.name }}
        </p>

        <!-- Quoted reply -->
        <button
          v-if="m.reply_to"
          type="button"
          class="mb-1.5 block w-full rounded-lg border-l-4 px-2.5 py-1.5 text-left text-xs"
          :class="
            m.is_mine
              ? 'border-white/60 bg-white/15 text-chat-own-contrast/90'
              : 'border-primary bg-highlight text-ink'
          "
          @click="emit('jump', m.reply_to.id)"
        >
          <span class="block font-semibold">{{ m.reply_to.author_name ?? 'Message' }}</span>
          <span class="block truncate" :class="{ italic: m.reply_to.redacted }">
            {{ m.reply_to.redacted ? 'Message deleted' : m.reply_to.excerpt }}
          </span>
        </button>

        <p v-if="m.redacted" class="text-sm italic opacity-70">Message deleted</p>

        <template v-else>
          <!-- Provenance tag on a forwarded copy, Messenger-style. -->
          <p v-if="m.forwarded" class="mb-0.5 flex items-center gap-1 text-[11px] italic opacity-70">
            <i class="pi pi-share-alt text-[10px]" aria-hidden="true" /> Forwarded
          </p>
          <template v-for="a in m.attachments" :key="a.id">
            <button
              v-if="a.kind === 'image'"
              type="button"
              class="mb-1 block overflow-hidden rounded-xl"
              aria-label="Open image"
              @click="emit('open-media', a)"
            >
              <img
                :src="a.url"
                alt="Shared image"
                class="max-h-64 w-auto"
                :class="compact && '!max-h-40'"
                loading="lazy"
              />
            </button>
            <VoiceBubble
              v-else-if="a.kind === 'voice'"
              :src="a.url"
              :duration-ms="a.duration_ms"
              :on-primary="m.is_mine"
            />
            <a v-else :href="a.url" target="_blank" rel="noopener" class="mb-1 block underline">
              <i class="pi pi-paperclip text-xs" aria-hidden="true" /> Download file
            </a>
          </template>

          <!-- Shared-record card (RBAC-gated server-side). A live-broadcast share
               carries subject_type but no per-user card, so fall back to generic. -->
          <div
            v-if="m.subject || m.subject_type"
            class="mb-1 rounded-lg border border-line bg-ground p-2.5 text-sm text-ink"
            :class="compact && '!p-2'"
          >
            <span v-if="!m.subject || m.subject.restricted" class="text-mute">
              <i class="pi pi-lock text-xs" aria-hidden="true" /> A record was shared
            </span>
            <RouterLink
              v-else
              :to="m.subject.link"
              class="flex items-center gap-2 font-medium text-primary-600 hover:underline dark:text-primary-400"
            >
              <i class="pi pi-file" aria-hidden="true" /> {{ m.subject.label }}
            </RouterLink>
          </div>

          <p
            v-if="m.body"
            class="whitespace-pre-wrap break-words text-sm native:text-[15px] native:leading-snug"
          >
            {{ m.body }}
          </p>
        </template>

        <!-- Meta line: time + my ticks (clock → ✓ → ✓✓), once per run. -->
        <p
          v-if="!m.redacted && (groupLast || m.pending || m.failed) && !compact"
          class="num mt-0.5 flex items-center justify-end gap-1 text-right text-[10px] opacity-70"
        >
          <span v-if="m.edited_at" class="italic">edited</span>
          {{ formatTime(m.created_at) }}
          <template v-if="m.is_mine">
            <i v-if="m.pending" class="pi pi-clock text-[10px]" aria-hidden="true" title="Sending…" />
            <i
              v-else-if="m.failed"
              class="pi pi-exclamation-circle text-[10px] !text-red-300"
              aria-hidden="true"
            />
            <span v-else class="relative inline-block w-4" :title="seen ? 'Seen' : 'Sent'">
              <i class="pi pi-check absolute left-0 top-1/2 -translate-y-1/2 text-[9px]" aria-hidden="true" />
              <i
                v-if="seen"
                class="pi pi-check absolute left-[5px] top-1/2 -translate-y-1/2 text-[9px] text-sky-300"
                aria-hidden="true"
              />
            </span>
          </template>
        </p>

        <!-- Failed send: retry / discard, WhatsApp-style. -->
        <div v-if="m.failed" class="mt-1 flex items-center gap-2 text-[11px]">
          <span class="font-medium" :class="m.is_mine ? 'text-red-200' : 'text-danger'">Not sent</span>
          <button type="button" class="underline" @click="emit('retry', m)">Retry</button>
          <button type="button" class="underline opacity-80" @click="emit('discard', m)">Discard</button>
        </div>
      </div>

      <!-- Reaction pills -->
      <div
        v-if="m.reactions?.length"
        class="relative z-[1] -mt-1.5 flex flex-wrap gap-1 px-1"
        :class="m.is_mine ? 'justify-end' : 'justify-start'"
      >
        <button
          v-for="g in m.reactions"
          :key="g.emoji"
          type="button"
          class="flex items-center gap-0.5 rounded-full border border-line bg-card px-1.5 py-0.5 text-xs shadow-card"
          :class="g.mine && 'border-primary bg-highlight'"
          :title="g.users.map((u) => u.name).join(', ')"
          :disabled="!canPost"
          @click="emit('react', { message: m, emoji: g.emoji })"
        >
          {{ g.emoji }}
          <span v-if="g.count > 1" class="num text-[10px] text-mute">{{ g.count }}</span>
        </button>
      </div>

      <!-- Web hover affordances: react + reply (touch uses long-press/swipe). -->
      <div
        v-if="!isNative && interactive"
        class="absolute top-1/2 hidden -translate-y-1/2 items-center gap-0.5 group-hover:flex"
        :class="m.is_mine ? 'right-full mr-1.5' : 'left-full ml-1.5'"
      >
        <button
          type="button"
          class="flex h-7 w-7 items-center justify-center rounded-full border border-line bg-card text-mute shadow-card hover:text-ink"
          aria-label="React"
          @click="menuOpen = !menuOpen"
        >
          <i class="pi pi-face-smile text-xs" aria-hidden="true" />
        </button>
        <button
          type="button"
          class="flex h-7 w-7 items-center justify-center rounded-full border border-line bg-card text-mute shadow-card hover:text-ink"
          aria-label="Reply"
          @click="emit('reply', m)"
        >
          <i class="pi pi-reply text-xs" aria-hidden="true" />
        </button>
      </div>
    </div>
  </div>
</template>
