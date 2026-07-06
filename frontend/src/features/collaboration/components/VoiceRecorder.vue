<script setup>
import { onBeforeUnmount, ref } from 'vue'
import { toastError } from '@/composables/useConfirm'

// Records a voice note with the MediaRecorder API and emits it as a File plus its
// duration. Emits: recorded({ file, durationMs }).
//
// getUserMedia only exists in a *secure context* (HTTPS or localhost) — over a
// plain-HTTP LAN origin (e.g. a phone hitting http://192.168.x.x) it is missing
// entirely. We detect that up front and, on tap, tell the user exactly why we
// can't record instead of failing silently. Codec/extension are negotiated so
// the note works across Chrome/Firefox (webm/ogg) and Safari/iOS (mp4).
const emit = defineEmits(['recorded'])

const MAX_SECONDS = 5 * 60 // auto-stop safeguard (keeps notes under the 25 MB cap)

const recording = ref(false)
const elapsed = ref(0)

// Capability probe, evaluated once. `reason` is shown to the user on tap.
const support = (() => {
  if (typeof window === 'undefined') return { ok: false, reason: '' }
  if (!window.isSecureContext) {
    return {
      ok: false,
      reason:
        'Voice notes need a secure (HTTPS) connection. Open the app over HTTPS or via localhost to record.',
    }
  }
  const hasMic = !!navigator.mediaDevices?.getUserMedia
  if (!hasMic || !('MediaRecorder' in window)) {
    return {
      ok: false,
      reason: 'This browser can’t record audio. Try a recent Chrome, Safari, or Firefox.',
    }
  }
  return { ok: true, reason: '' }
})()

let recorder = null
let chunks = []
let stream = null
let startedAt = 0
let timer = null

// First MediaRecorder-supported container, most-compatible first. Safari only
// supports audio/mp4; Chrome/Firefox prefer webm/opus.
function pickMimeType() {
  if (typeof MediaRecorder === 'undefined' || !MediaRecorder.isTypeSupported) return ''
  const candidates = [
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/ogg;codecs=opus',
    'audio/ogg',
    'audio/mp4',
    'audio/mpeg',
  ]
  return candidates.find((t) => MediaRecorder.isTypeSupported(t)) ?? ''
}

function extFor(type) {
  if (type.includes('ogg')) return 'ogg'
  if (type.includes('mp4') || type.includes('aac') || type.includes('m4a')) return 'm4a'
  if (type.includes('mpeg') || type.includes('mp3')) return 'mp3'
  if (type.includes('wav')) return 'wav'
  return 'webm'
}

async function start() {
  if (recording.value) return
  if (!support.ok) {
    if (support.reason) toastError(support.reason)
    return
  }
  try {
    stream = await navigator.mediaDevices.getUserMedia({ audio: true })
  } catch (err) {
    const name = err?.name
    if (name === 'NotAllowedError' || name === 'SecurityError') {
      toastError(
        'Microphone access was blocked. Allow it in your browser’s site settings, then try again.',
      )
    } else if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
      toastError('No microphone was found on this device.')
    } else {
      toastError('Could not start recording.' + (err?.message ? ` (${err.message})` : ''))
    }
    return
  }

  chunks = []
  const mimeType = pickMimeType()
  try {
    recorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined)
  } catch {
    recorder = new MediaRecorder(stream) // fall back to the UA default
  }
  recorder.ondataavailable = (e) => {
    if (e.data?.size) chunks.push(e.data)
  }
  recorder.onerror = () => {
    toastError('Recording stopped unexpectedly.')
    cancel()
  }
  recorder.onstop = () => {
    const durationMs = Date.now() - startedAt
    const type = (recorder.mimeType || mimeType || 'audio/webm').split(';')[0]
    const file = new File(chunks, `voice.${extFor(type)}`, { type })
    cleanup()
    if (file.size) emit('recorded', { file, durationMs })
  }
  startedAt = Date.now()
  recorder.start(1000) // 1s timeslice: flushes data periodically (reliable on Safari)
  recording.value = true
  timer = setInterval(() => {
    elapsed.value = Math.floor((Date.now() - startedAt) / 1000)
    if (elapsed.value >= MAX_SECONDS) stop()
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
  <div class="flex items-center gap-2">
    <button
      v-if="!recording"
      type="button"
      class="flex min-h-[44px] min-w-[44px] items-center justify-center rounded-full text-mute transition-colors hover:bg-surface-100 hover:text-ink dark:hover:bg-surface-800"
      :class="{ 'opacity-60': !support.ok }"
      aria-label="Record a voice note"
      :title="support.ok ? 'Record a voice note' : support.reason"
      @click="start"
    >
      <i class="pi pi-microphone" aria-hidden="true" />
    </button>
    <template v-else>
      <span class="num flex items-center gap-1.5 text-sm font-medium text-danger">
        <span class="h-2 w-2 animate-pulse rounded-full bg-danger" aria-hidden="true" />
        {{ mmss(elapsed) }}
      </span>
      <button
        type="button"
        class="flex min-h-[44px] items-center rounded-full px-3 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
        @click="stop"
      >
        Send
      </button>
      <button
        type="button"
        class="flex min-h-[44px] items-center px-2 text-sm text-mute hover:text-ink"
        @click="cancel"
      >
        Cancel
      </button>
    </template>
  </div>
</template>
