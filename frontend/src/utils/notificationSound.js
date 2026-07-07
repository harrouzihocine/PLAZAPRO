// Short chimes for live notifications, synthesized with the Web Audio API so
// we don't ship audio assets. Browsers keep an AudioContext suspended until
// the user interacts with the page, so we unlock it on the first pointer/key
// event; a chime that fires before that is skipped silently.
//
// Two sound sets: the web keeps its original discreet tones; the Android
// shell (isNativeApp) gets richer "app-grade" ones — a marimba tri-tone for
// notifications and a water-drop pop + ding for chat — still clearly distinct
// from each other so a message is recognizable without looking.
import { isNativeApp } from '@/utils/nativeApp'

let ctx = null

function getContext() {
  if (!ctx) {
    const AudioCtx = window.AudioContext || window.webkitAudioContext
    if (!AudioCtx) return null
    ctx = new AudioCtx()
    if (ctx.state === 'suspended') {
      const unlock = () => {
        ctx.resume()
        document.removeEventListener('pointerdown', unlock)
        document.removeEventListener('keydown', unlock)
      }
      document.addEventListener('pointerdown', unlock)
      document.addEventListener('keydown', unlock)
    }
  }
  return ctx
}

function tone(context, freq, start, duration, peak, type = 'sine') {
  const osc = context.createOscillator()
  const gain = context.createGain()
  osc.type = type
  osc.frequency.value = freq
  gain.gain.setValueAtTime(0, start)
  gain.gain.linearRampToValueAtTime(peak, start + 0.01)
  gain.gain.exponentialRampToValueAtTime(0.0001, start + duration)
  osc.connect(gain)
  gain.connect(context.destination)
  osc.start(start)
  osc.stop(start + duration)
}

// Soft mallet hit: sine fundamental + a quiet triangle octave for shimmer.
function marimba(context, freq, start, duration, peak) {
  tone(context, freq, start, duration, peak)
  tone(context, freq * 2, start, duration * 0.6, peak * 0.22, 'triangle')
}

// Pitch-glide blip (the "water drop").
function glide(context, from, to, start, duration, peak) {
  const osc = context.createOscillator()
  const gain = context.createGain()
  osc.type = 'sine'
  osc.frequency.setValueAtTime(from, start)
  osc.frequency.exponentialRampToValueAtTime(to, start + duration)
  gain.gain.setValueAtTime(0, start)
  gain.gain.linearRampToValueAtTime(peak, start + 0.008)
  gain.gain.exponentialRampToValueAtTime(0.0001, start + duration)
  osc.connect(gain)
  gain.connect(context.destination)
  osc.start(start)
  osc.stop(start + duration)
}

export function playNotificationSound() {
  const context = getContext()
  if (!context || context.state !== 'running') return
  const now = context.currentTime
  if (isNativeApp()) {
    // App notification: ascending marimba tri-tone (E5 → B5 → E6).
    marimba(context, 659.25, now, 0.45, 0.16)
    marimba(context, 987.77, now + 0.11, 0.45, 0.16)
    marimba(context, 1318.51, now + 0.22, 0.55, 0.13)
    return
  }
  tone(context, 880, now, 0.35, 0.18) // A5
  tone(context, 1174.66, now + 0.12, 0.4, 0.18) // D6
}

// Quicker, softer sound for incoming chat messages — audibly distinct from
// the notification chime so a message can be told apart without looking.
export function playChatSound() {
  const context = getContext()
  if (!context || context.state !== 'running') return
  const now = context.currentTime
  if (isNativeApp()) {
    // Chat: water-drop pop, then a feather-light high ding (G6).
    glide(context, 950, 360, now, 0.09, 0.2)
    marimba(context, 1567.98, now + 0.1, 0.3, 0.1)
    return
  }
  tone(context, 523.25, now, 0.12, 0.14) // C5
  tone(context, 783.99, now + 0.07, 0.2, 0.14) // G5
}
