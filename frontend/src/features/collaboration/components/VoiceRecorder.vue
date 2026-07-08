<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import { toastError, toastInfo } from '@/composables/useConfirm'
import { t } from '@/i18n'

// Messenger-style voice notes: HOLD the mic to record, RELEASE to send, SLIDE
// LEFT to cancel. Emits recorded({ file, durationMs }) on a successful take and
// recording(bool) so the composer can swap its row for the recording UI.
//
// The gesture grammar (pointer events, capture on the mic so the finger can
// wander):
//   pointerdown  → start recording (after the one-time mic permission)
//   pointermove  → track the horizontal slide; past CANCEL_AT px arms cancel
//   pointerup    → armed-cancel ? discard : (too short ? hint : send)
//
// getUserMedia only exists in a *secure context* (HTTPS or localhost) — over a
// plain-HTTP LAN origin it is missing entirely. We detect that up front and,
// on tap, tell the user exactly why we can't record instead of failing
// silently. Codec/extension are negotiated so the note works across
// Chrome/Firefox (webm/ogg) and Safari/iOS (mp4).
const emit = defineEmits(['recorded', 'recording'])

const MAX_SECONDS = 5 * 60 // auto-stop safeguard (keeps notes under the 25 MB cap)
const MIN_MS = 700 // a shorter press is a tap, not a voice note
const CANCEL_AT = 72 // slide this many px left to arm cancel

const recording = ref(false)
const elapsed = ref(0)
const dx = ref(0) // finger slide while recording (≤ 0)
const willCancel = computed(() => dx.value <= -CANCEL_AT)

// Capability probe, evaluated once. `reason` is shown to the user on tap.
const support = (() => {
  if (typeof window === 'undefined') return { ok: false, reason: '' }
  if (!window.isSecureContext) {
    return {
      ok: false,
      reason:
        t('chat.voiceNeedsHttps'),
    }
  }
  const hasMic = !!navigator.mediaDevices?.getUserMedia
  if (!hasMic || !('MediaRecorder' in window)) {
    return {
      ok: false,
      reason: t('chat.voiceNoRecorder'),
    }
  }
  return { ok: true, reason: '' }
})()

let recorder = null
let chunks = []
let stream = null
let startedAt = 0
let timer = null
let pointerDown = false
let downX = 0
let sendOnStop = false

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

async function onPointerDown(e) {
  if (recording.value) return
  if (!support.ok) {
    if (support.reason) toastError(support.reason)
    return
  }
  pointerDown = true
  downX = e.clientX
  dx.value = 0
  try {
    e.currentTarget?.setPointerCapture?.(e.pointerId)
  } catch {
    /* capture is best-effort — the up handler still fires on the button */
  }
  try {
    stream = await navigator.mediaDevices.getUserMedia({ audio: true })
  } catch (err) {
    pointerDown = false
    const name = err?.name
    if (name === 'NotAllowedError' || name === 'SecurityError') {
      toastError(
        t('chat.micBlocked'),
      )
    } else if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
      toastError(t('chat.noMic'))
    } else {
      toastError(t('chat.recordStartFailed') + (err?.message ? ` (${err.message})` : ''))
    }
    return
  }

  // The permission prompt (first use) can outlive the press — if the finger is
  // gone by the time the mic is ours, don't start a ghost recording.
  if (!pointerDown) {
    stream.getTracks().forEach((t) => t.stop())
    stream = null
    return
  }

  chunks = []
  const mimeType = pickMimeType()
  try {
    recorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined)
  } catch {
    recorder = new MediaRecorder(stream) // fall back to the UA default
  }
  recorder.ondataavailable = (event) => {
    if (event.data?.size) chunks.push(event.data)
  }
  recorder.onerror = () => {
    toastError(t('chat.recordStopped'))
    discard()
  }
  recorder.onstop = () => {
    const durationMs = Date.now() - startedAt
    const type = (recorder.mimeType || mimeType || 'audio/webm').split(';')[0]
    const file = new File(chunks, `voice.${extFor(type)}`, { type })
    const shouldSend = sendOnStop
    cleanup()
    if (shouldSend && file.size) emit('recorded', { file, durationMs })
  }
  startedAt = Date.now()
  sendOnStop = false
  recorder.start(1000) // 1s timeslice: flushes data periodically (reliable on Safari)
  recording.value = true
  emit('recording', true)
  navigator.vibrate?.(30)
  timer = setInterval(() => {
    elapsed.value = Math.floor((Date.now() - startedAt) / 1000)
    // Hands-free safety net: at the cap the note sends itself.
    if (elapsed.value >= MAX_SECONDS) finish(true)
  }, 250)
}

function onPointerMove(e) {
  if (!recording.value || !pointerDown) return
  dx.value = Math.max(-160, Math.min(0, e.clientX - downX))
}

function onPointerUp() {
  if (!pointerDown) return
  pointerDown = false
  if (!recording.value) return
  if (willCancel.value) {
    navigator.vibrate?.(10)
    discard()
    return
  }
  if (Date.now() - startedAt < MIN_MS) {
    discard()
    toastInfo(t('chat.holdToRecord'))
    return
  }
  finish(true)
}

// Stop the recorder; onstop sends when `send` is true.
function finish(send) {
  if (!recorder || !recording.value) return
  sendOnStop = send
  recording.value = false
  emit('recording', false)
  recorder.stop()
}

function discard() {
  if (recorder && recording.value) {
    recorder.onstop = null
    recorder.stop()
  }
  recording.value = false
  emit('recording', false)
  cleanup()
}

function cleanup() {
  if (timer) clearInterval(timer)
  timer = null
  stream?.getTracks().forEach((t) => t.stop())
  stream = null
  recorder = null
  elapsed.value = 0
  dx.value = 0
  pointerDown = false
}

function mmss(s) {
  const m = Math.floor(s / 60)
  return `${m}:${String(s % 60).padStart(2, '0')}`
}

onBeforeUnmount(discard)
</script>

<template>
  <div class="flex min-w-0 items-center gap-2" :class="recording && 'flex-1'">
    <!-- Recording takeover: timer + slide-to-cancel hint fill the row. -->
    <template v-if="recording">
      <span class="num flex shrink-0 items-center gap-1.5 text-sm font-medium text-danger">
        <span class="h-2 w-2 animate-pulse rounded-full bg-danger" aria-hidden="true" />
        {{ mmss(elapsed) }}
      </span>
      <span
        class="min-w-0 flex-1 truncate text-center text-xs transition-colors"
        :class="willCancel ? 'font-semibold text-danger' : 'text-mute'"
        aria-live="polite"
      >
        <template v-if="willCancel">{{ $t('chat.releaseToCancel') }}</template>
        <template v-else><i class="pi pi-angle-left" aria-hidden="true" /> {{ $t('chat.slideToCancel') }}</template>
      </span>
    </template>

    <button
      type="button"
      class="flex min-h-[44px] min-w-[44px] touch-none select-none items-center justify-center rounded-full transition-all"
      :class="[
        recording
          ? willCancel
            ? 'bg-surface-300 text-ink dark:bg-surface-700'
            : 'bg-danger text-white shadow-pop'
          : 'text-mute hover:bg-surface-100 hover:text-ink dark:hover:bg-surface-800',
        !support.ok && 'opacity-60',
      ]"
      :style="
        recording
          ? { transform: `translateX(${dx}px) scale(${willCancel ? 1.1 : 1.25})` }
          : undefined
      "
      :aria-label="recording ? 'Recording — release to send' : 'Hold to record a voice note'"
      :title="support.ok ? 'Hold to record, release to send' : support.reason"
      @pointerdown.prevent="onPointerDown"
      @pointermove="onPointerMove"
      @pointerup="onPointerUp"
      @pointercancel="onPointerUp"
      @contextmenu.prevent
    >
      <i class="pi pi-microphone" aria-hidden="true" />
    </button>
  </div>
</template>
