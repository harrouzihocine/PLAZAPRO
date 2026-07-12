<script setup>
import { nextTick, ref, watch } from 'vue'
import VoiceRecorder from '@/features/collaboration/components/VoiceRecorder.vue'
import AttachSheet from '@/components/ui/AttachSheet.vue'
import { messagePreview } from '@/features/collaboration/preview'
import { isNativeApp } from '@/utils/nativeApp'

// The message composer: text, an image/file attach, a voice-note recorder and
// a WhatsApp-style reply banner when quoting. Emits send-text(string),
// send-file({ file, durationMs }), typing (throttled by the parent/store) and
// cancel-reply; the parent wires these to the chat store so this component
// stays presentational. When `editing` is set the composer switches to edit
// mode (prefilled text, ✓ saves via save-edit, attach/voice hidden).
const props = defineProps({
  disabled: Boolean,
  // The message being quoted (null = plain send). Shown as a banner above the
  // input; the parent attaches reply_to_id on send.
  replyTo: { type: Object, default: null },
  // The message being edited (null = plain send).
  editing: { type: Object, default: null },
})
const emit = defineEmits([
  'send-text',
  'send-file',
  'cancel-reply',
  'typing',
  'save-edit',
  'cancel-edit',
])

const text = ref('')
const fileInput = ref(null)
const textInput = ref(null)

// Messenger-style auto-grow: the textarea tracks its content height up to the
// max-h-32 CSS cap, after which it scrolls. Watching `text` (with nextTick, so
// the DOM value is in) also covers programmatic changes — clearing after send,
// prefilling on edit — not just keystrokes.
const MAX_INPUT_HEIGHT = 128 // keep in sync with max-h-32 on the textarea
function autogrow() {
  const el = textInput.value
  if (!el) return
  el.style.height = 'auto'
  el.style.height = `${Math.min(el.scrollHeight, MAX_INPUT_HEIGHT)}px`
}
watch(text, () => nextTick(autogrow))

// While a voice note is being held down, the recorder takes over the whole
// composer row (timer + slide-to-cancel), Messenger-style.
const recordingVoice = ref(false)

// APK: a chevron beside the input drops the soft keyboard on demand — the
// only stock way out is tapping empty page, which a full-height thread barely
// has. pointerdown.prevent keeps the tap from re-focusing anything; the blur
// is what closes the keyboard.
const inputFocused = ref(false)
function dismissKeyboard() {
  textInput.value?.blur()
}

// Android shell: the paperclip opens a WhatsApp-style source sheet (camera /
// gallery multi-select / document) instead of the bare file manager. No video
// source here — chat's backend reads video containers as voice notes.
const isNative = isNativeApp()
const attachOpen = ref(false)
const cameraInput = ref(null)
const galleryInput = ref(null)
const documentInput = ref(null)

// Entering edit mode prefills the draft; leaving restores an empty composer.
watch(
  () => props.editing,
  (m) => {
    text.value = m ? (m.body ?? '') : ''
  },
)

function submitText() {
  const value = text.value.trim()
  if (!value) return
  if (props.editing) {
    emit('save-edit', value)
  } else {
    emit('send-text', value)
  }
  text.value = ''
}

function pickFile() {
  if (isNative) attachOpen.value = true
  else fileInput.value?.click()
}

function onAttachPick(kind) {
  attachOpen.value = false
  const input = { 'camera-photo': cameraInput, library: galleryInput, document: documentInput }[
    kind
  ]
  input?.value?.click()
}

function onFile(event) {
  // Multi-select sends one message per file, like the messaging apps do.
  for (const file of event.target.files ?? []) emit('send-file', { file, durationMs: null })
  event.target.value = '' // allow re-picking the same file
}

function onVoice({ file, durationMs }) {
  emit('send-file', { file, durationMs })
}

const replyExcerpt = messagePreview
</script>

<template>
  <div>
    <!-- Edit banner -->
    <div
      v-if="editing"
      class="mb-1.5 flex items-center gap-2 rounded-xl border-s-4 border-primary bg-highlight px-3 py-1.5"
    >
      <div class="min-w-0 flex-1 text-xs">
        <p class="font-semibold text-ink"><i class="pi pi-pencil text-[10px]" aria-hidden="true" /> Edit message</p>
        <p class="truncate text-mute">{{ editing.body }}</p>
      </div>
      <button
        type="button"
        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-mute hover:text-ink"
        :aria-label="$t('chat.cancelEdit')"
        @click="emit('cancel-edit')"
      >
        <i class="pi pi-times text-xs" aria-hidden="true" />
      </button>
    </div>

    <!-- Reply banner (WhatsApp-style quote above the input) -->
    <div
      v-else-if="replyTo"
      class="mb-1.5 flex items-center gap-2 rounded-xl border-s-4 border-primary bg-highlight px-3 py-1.5"
    >
      <div class="min-w-0 flex-1 text-xs">
        <p class="font-semibold text-ink">
          {{ $t('chat.replyingTo', { name: replyTo.is_mine ? $t('chat.yourself') : (replyTo.author?.name ?? $t('chat.message')) }) }}
        </p>
        <p class="truncate text-mute">{{ replyExcerpt(replyTo) }}</p>
      </div>
      <button
        type="button"
        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-mute hover:text-ink"
        :aria-label="$t('chat.cancelReply')"
        @click="emit('cancel-reply')"
      >
        <i class="pi pi-times text-xs" aria-hidden="true" />
      </button>
    </div>

    <div class="flex items-end gap-1.5">
    <button
      v-if="isNative"
      v-show="inputFocused && !recordingVoice"
      type="button"
      class="flex min-h-[44px] min-w-[44px] items-center justify-center rounded-full text-mute transition-colors hover:bg-surface-100 hover:text-ink dark:hover:bg-surface-800"
      :aria-label="$t('chat.hideKeyboard')"
      @pointerdown.prevent="dismissKeyboard"
    >
      <i class="pi pi-chevron-down" aria-hidden="true" />
    </button>
    <button
      v-show="!recordingVoice && !editing"
      type="button"
      class="flex min-h-[44px] min-w-[44px] items-center justify-center rounded-full text-mute transition-colors hover:bg-surface-100 hover:text-ink disabled:opacity-50 dark:hover:bg-surface-800"
      :aria-label="$t('chat.attach')"
      :disabled="disabled"
      @click="pickFile"
    >
      <i class="pi pi-paperclip" aria-hidden="true" />
    </button>
    <input
      ref="fileInput"
      type="file"
      class="hidden"
      accept="image/*,audio/*,application/pdf"
      @change="onFile"
    />
    <input
      ref="cameraInput"
      type="file"
      accept="image/*"
      capture="environment"
      class="hidden"
      @change="onFile"
    />
    <input
      ref="galleryInput"
      type="file"
      accept="image/*"
      multiple
      class="hidden"
      @change="onFile"
    />
    <input
      ref="documentInput"
      type="file"
      accept="application/pdf"
      class="hidden"
      @change="onFile"
    />
    <AttachSheet
      :open="attachOpen"
      :kinds="['camera-photo', 'library', 'document']"
      @close="attachOpen = false"
      @pick="onAttachPick"
    />

    <textarea
      v-show="!recordingVoice"
      ref="textInput"
      v-model="text"
      rows="1"
      :placeholder="$t('chat.messagePlaceholder')"
      class="max-h-32 min-h-[44px] flex-1 resize-none rounded-3xl border border-line bg-ground px-4 py-2.5 text-sm text-ink outline-none transition-colors focus:border-primary"
      :disabled="disabled"
      @input="emit('typing')"
      @keydown.enter.exact.prevent="submitText"
      @focus="inputFocused = true"
      @blur="inputFocused = false"
    ></textarea>

    <VoiceRecorder v-if="!editing" @recorded="onVoice" @recording="(v) => (recordingVoice = v)" />

    <button
      v-show="!recordingVoice"
      type="button"
      class="flex min-h-[44px] min-w-[44px] items-center justify-center rounded-full bg-primary text-primary-contrast transition-opacity hover:opacity-90 disabled:opacity-40"
      :aria-label="editing ? $t('chat.saveChanges') : $t('chat.sendMessage')"
      :disabled="disabled || !text.trim()"
      @click="submitText"
    >
      <i :class="editing ? 'pi pi-check' : 'pi pi-send'" aria-hidden="true" />
    </button>
    </div>
  </div>
</template>
