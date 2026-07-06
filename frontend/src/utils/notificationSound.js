// Short two-tone chime for live notifications, synthesized with the Web Audio
// API so we don't ship an audio asset. Browsers keep an AudioContext suspended
// until the user interacts with the page, so we unlock it on the first
// pointer/key event; a chime that fires before that is skipped silently.
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

function tone(context, freq, start, duration, peak) {
  const osc = context.createOscillator()
  const gain = context.createGain()
  osc.type = 'sine'
  osc.frequency.value = freq
  gain.gain.setValueAtTime(0, start)
  gain.gain.linearRampToValueAtTime(peak, start + 0.01)
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
  tone(context, 880, now, 0.35, 0.18) // A5
  tone(context, 1174.66, now + 0.12, 0.4, 0.18) // D6
}

// Quicker, softer "pop" for incoming chat messages — audibly distinct from the
// bell chime so a message can be told apart without looking.
export function playChatSound() {
  const context = getContext()
  if (!context || context.state !== 'running') return
  const now = context.currentTime
  tone(context, 523.25, now, 0.12, 0.14) // C5
  tone(context, 783.99, now + 0.07, 0.2, 0.14) // G5
}
