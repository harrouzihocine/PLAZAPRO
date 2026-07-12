<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { isNativeApp } from '@/utils/nativeApp'

// APK-wide "hide keyboard" button, mounted once in App.vue: a floating
// chevron just above the soft keyboard whenever a text field is focused and
// the keyboard actually holds the viewport. Stock Android (gesture nav)
// offers no way to drop the keyboard except tapping empty page — which a
// full-height form barely has. Components with their own dismiss affordance
// (the chat composer's in-row chevron) opt out via data-kb-dismiss-local.

const isNative = isNativeApp()

const focusedEl = ref(null)
const shrunk = ref(false)

// Text-ENTRY controls only: date/time inputs and selects summon native
// pickers, not the keyboard.
const TEXT_TYPES = new Set(['text', 'search', 'email', 'tel', 'url', 'number', 'password'])
function isTextEntry(el) {
  if (!el || !(el instanceof Element)) return false
  if (el.isContentEditable) return true
  if (el.tagName === 'TEXTAREA') return true
  return el.tagName === 'INPUT' && TEXT_TYPES.has(el.type)
}

function onFocusIn(e) {
  const el = e.target
  focusedEl.value = isTextEntry(el) && !el.closest('[data-kb-dismiss-local]') ? el : null
}

let blurTimer = null
function onFocusOut() {
  // Grace period: hopping field→field fires focusout+focusin back to back,
  // and focusin (which re-evaluates) lands within it.
  clearTimeout(blurTimer)
  blurTimer = setTimeout(() => {
    if (!isTextEntry(document.activeElement)) focusedEl.value = null
  }, 80)
}

// The keyboard signal: windowSoftInputMode=adjustResize (shell v2.2.0)
// shrinks the viewport while a field is focused. idleHeight re-learns the
// no-keyboard height from any resize that happens unfocused, so it tracks
// orientation changes too. Without this gate the button would linger after
// the system back gesture already closed the keyboard.
let idleHeight = window.innerHeight
function onResize() {
  if (!focusedEl.value) {
    idleHeight = window.innerHeight
    shrunk.value = false
    return
  }
  shrunk.value = window.innerHeight < idleHeight - 120
}

const visible = computed(() => !!focusedEl.value && shrunk.value)

function dismiss() {
  focusedEl.value?.blur?.()
  focusedEl.value = null
}

onMounted(() => {
  if (!isNative) return
  document.addEventListener('focusin', onFocusIn)
  document.addEventListener('focusout', onFocusOut)
  window.addEventListener('resize', onResize)
})
onBeforeUnmount(() => {
  clearTimeout(blurTimer)
  document.removeEventListener('focusin', onFocusIn)
  document.removeEventListener('focusout', onFocusOut)
  window.removeEventListener('resize', onResize)
})
</script>

<template>
  <!-- fixed bottom = the keyboard's top edge under adjustResize; z above the
       PrimeVue overlay stack so fields inside dialogs/sheets get it too. -->
  <button
    v-if="isNative"
    v-show="visible"
    type="button"
    class="fixed bottom-2 end-2 z-[12000] flex h-10 w-10 items-center justify-center rounded-full border border-line bg-card/95 text-mute shadow-pop backdrop-blur"
    :aria-label="$t('shell.hideKeyboard')"
    @pointerdown.prevent="dismiss"
  >
    <i class="pi pi-chevron-down" aria-hidden="true" />
  </button>
</template>
