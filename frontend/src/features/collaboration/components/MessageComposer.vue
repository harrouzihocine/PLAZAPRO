<script setup>
import { ref } from 'vue'
import VoiceRecorder from '@/features/collaboration/components/VoiceRecorder.vue'

// The message composer: text, an image/file attach, and a voice-note recorder.
// Emits send-text(string) and send-file({ file, durationMs }); the parent wires
// these to the chat store so this component stays presentational.
defineProps({ disabled: Boolean })
const emit = defineEmits(['send-text', 'send-file'])

const text = ref('')
const fileInput = ref(null)

function submitText() {
  const value = text.value.trim()
  if (!value) return
  emit('send-text', value)
  text.value = ''
}

function pickFile() {
  fileInput.value?.click()
}

function onFile(event) {
  const file = event.target.files?.[0]
  if (file) emit('send-file', { file, durationMs: null })
  event.target.value = '' // allow re-picking the same file
}

function onVoice({ file, durationMs }) {
  emit('send-file', { file, durationMs })
}
</script>

<template>
  <div class="flex items-end gap-1.5">
    <button
      type="button"
      class="flex min-h-[44px] min-w-[44px] items-center justify-center rounded-full text-mute transition-colors hover:bg-surface-100 hover:text-ink disabled:opacity-50 dark:hover:bg-surface-800"
      aria-label="Attach a photo or file"
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

    <textarea
      v-model="text"
      rows="1"
      placeholder="Message…"
      class="max-h-32 min-h-[44px] flex-1 resize-none rounded-3xl border border-line bg-ground px-4 py-2.5 text-sm text-ink outline-none transition-colors focus:border-primary"
      :disabled="disabled"
      @keydown.enter.exact.prevent="submitText"
    ></textarea>

    <VoiceRecorder @recorded="onVoice" />

    <button
      type="button"
      class="flex min-h-[44px] min-w-[44px] items-center justify-center rounded-full bg-primary text-primary-contrast transition-opacity hover:opacity-90 disabled:opacity-40"
      aria-label="Send message"
      :disabled="disabled || !text.trim()"
      @click="submitText"
    >
      <i class="pi pi-send" aria-hidden="true" />
    </button>
  </div>
</template>
