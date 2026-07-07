<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'

// WhatsApp-style voice-note player: round play button, seek bar, ticking
// elapsed/total time. Replaces the browser's default <audio controls> chrome
// inside chat bubbles (which looks foreign in the APK webview).
const props = defineProps({
  src: { type: String, required: true },
  durationMs: { type: Number, default: null },
  // True inside my own (primary-colored) bubble — switches to contrast tones.
  onPrimary: { type: Boolean, default: false },
})

const audio = new Audio()
audio.preload = 'metadata'
audio.src = props.src

const playing = ref(false)
const position = ref(0) // seconds
const loadedDuration = ref(null)

audio.addEventListener('timeupdate', () => (position.value = audio.currentTime))
audio.addEventListener('loadedmetadata', () => {
  if (Number.isFinite(audio.duration)) loadedDuration.value = audio.duration
})
audio.addEventListener('ended', () => {
  playing.value = false
  position.value = 0
})

const total = computed(() => {
  if (loadedDuration.value) return loadedDuration.value
  return props.durationMs ? props.durationMs / 1000 : 0
})

function toggle() {
  if (playing.value) {
    audio.pause()
    playing.value = false
  } else {
    audio.play().catch(() => {})
    playing.value = true
  }
}

function seek(event) {
  const value = Number(event.target.value)
  audio.currentTime = value
  position.value = value
}

function fmt(seconds) {
  const s = Math.max(0, Math.round(seconds))
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`
}

onBeforeUnmount(() => {
  audio.pause()
  audio.src = ''
})
</script>

<template>
  <div class="flex w-56 max-w-full items-center gap-2 py-1">
    <button
      type="button"
      class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
      :class="onPrimary ? 'bg-white/20 text-primary-contrast' : 'bg-highlight text-primary-700 dark:text-primary-300'"
      :aria-label="playing ? 'Pause voice note' : 'Play voice note'"
      @click="toggle"
    >
      <i :class="playing ? 'pi pi-pause' : 'pi pi-play'" class="text-sm" aria-hidden="true" />
    </button>
    <div class="min-w-0 flex-1">
      <input
        type="range"
        class="voice-seek w-full"
        :class="onPrimary ? 'voice-seek-contrast' : ''"
        min="0"
        :max="total || 1"
        step="0.1"
        :value="position"
        aria-label="Seek in voice note"
        @input="seek"
      />
      <p class="num mt-0.5 text-[10px]" :class="onPrimary ? 'text-primary-contrast/80' : 'text-mute'">
        {{ fmt(playing || position > 0 ? position : total) }}
      </p>
    </div>
  </div>
</template>

<style scoped>
.voice-seek {
  appearance: none;
  height: 4px;
  border-radius: 9999px;
  background: color-mix(in srgb, currentColor 25%, transparent);
  color: var(--p-primary-500, #b3903f);
  outline: none;
}
.voice-seek::-webkit-slider-thumb {
  appearance: none;
  height: 12px;
  width: 12px;
  border-radius: 9999px;
  background: currentColor;
}
.voice-seek-contrast {
  color: #fff;
}
</style>
