<script setup>
import { onBeforeUnmount, ref } from 'vue'

// Records a voice note with the MediaRecorder API and emits it as a File plus its
// duration. Microphone access is already permitted by the app's Permissions-Policy
// header. Emits: recorded({ file, durationMs }).
const emit = defineEmits(['recorded'])

const recording = ref(false)
const elapsed = ref(0)
const unsupported = ref(typeof window === 'undefined' || !('MediaRecorder' in window))

let recorder = null
let chunks = []
let stream = null
let startedAt = 0
let timer = null

async function start() {
  if (unsupported.value) return
  try {
    stream = await navigator.mediaDevices.getUserMedia({ audio: true })
  } catch {
    return // permission denied / no mic — silently no-op
  }
  chunks = []
  recorder = new MediaRecorder(stream)
  recorder.ondataavailable = (e) => {
    if (e.data.size) chunks.push(e.data)
  }
  recorder.onstop = () => {
    const durationMs = Date.now() - startedAt
    const type = recorder.mimeType || 'audio/webm'
    const ext = type.includes('ogg') ? 'ogg' : 'webm'
    const file = new File(chunks, `voice.${ext}`, { type })
    cleanup()
    emit('recorded', { file, durationMs })
  }
  startedAt = Date.now()
  recorder.start()
  recording.value = true
  timer = setInterval(() => {
    elapsed.value = Math.floor((Date.now() - startedAt) / 1000)
  }, 250)
}

function stop() {
  if (recorder && recording.value) {
    recording.value = false
    recorder.stop() // fires onstop -> emit
  }
}

function cancel() {
  if (recorder && recording.value) {
    recorder.onstop = null
    recorder.stop()
    recording.value = false
  }
  cleanup()
}

function cleanup() {
  if (timer) clearInterval(timer)
  timer = null
  stream?.getTracks().forEach((t) => t.stop())
  stream = null
  elapsed.value = 0
}

function mmss(s) {
  const m = Math.floor(s / 60)
  return `${m}:${String(s % 60).padStart(2, '0')}`
}

onBeforeUnmount(cleanup)
</script>

<template>
  <div v-if="!unsupported" class="flex items-center gap-2">
    <button
      v-if="!recording"
      type="button"
      class="min-h-[44px] min-w-[44px] rounded-token px-2 hover:bg-bg"
      aria-label="Record a voice note"
      @click="start"
    >
      🎤
    </button>
    <template v-else>
      <span class="flex items-center gap-1 text-sm text-danger">
        <span class="h-2 w-2 animate-pulse rounded-full bg-danger" aria-hidden="true" />
        {{ mmss(elapsed) }}
      </span>
      <button type="button" class="text-sm text-primary" @click="stop">Send</button>
      <button type="button" class="text-sm opacity-70" @click="cancel">Cancel</button>
    </template>
  </div>
</template>
