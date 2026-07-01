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
  <div class="flex items-end gap-2 border-t border-border bg-surface p-2">
    <button
      type="button"
      class="min-h-[44px] min-w-[44px] rounded-token px-2 hover:bg-bg"
      aria-label="Attach a photo or file"
      :disabled="disabled"
      @click="pickFile"
    >
      📎
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
      class="max-h-32 min-h-[44px] flex-1 resize-none rounded-token border border-border bg-bg px-3 py-2 text-ink outline-none focus:border-primary"
      :disabled="disabled"
      @keydown.enter.exact.prevent="submitText"
    ></textarea>

    <VoiceRecorder @recorded="onVoice" />

    <button
      type="button"
      class="min-h-[44px] rounded-token bg-primary px-4 text-on-primary disabled:opacity-50"
      :disabled="disabled || !text.trim()"
      @click="submitText"
    >
      Send
    </button>
  </div>
</template>
