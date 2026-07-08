<script setup>
import { computed, ref } from 'vue'
import { toastError, toastSuccess } from '@/composables/useConfirm'
import { pipelineApi } from '@/features/pipeline/api'
import { useAuthStore } from '@/features/settings/store'
import { isNativeApp } from '@/utils/nativeApp'

// The web-only click-to-call handoff: pushes the client's number to the
// agent's own phone, where the Android shell pops a "Call …" notification
// that opens the dialer in one tap. Renders nothing on the APK itself — there
// the plain tel: link is already one tap — and nothing without calls.log
// (the handoff exists to end in a call log).
const props = defineProps({
  clientId: { type: [String, Number], required: true },
  // 'sm' matches the compact WhatsApp icon in list rows / the profile card.
  size: { type: String, default: 'md' },
})

const auth = useAuthStore()
// computed, not a const: the grant can change mid-session (fetchMe on
// reconnect, profile updates re-set permissions) — mirrors ClientsView.
const show = computed(() => !isNativeApp() && auth.can('calls.log'))
const sending = ref(false)

async function send() {
  if (sending.value) return
  sending.value = true
  try {
    await pipelineApi.sendCallRequest(props.clientId)
    toastSuccess('Sent to your phone — tap the notification to call.')
  } catch (e) {
    toastError(e.response?.data?.message)
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <button
    v-if="show"
    type="button"
    class="inline-flex shrink-0 items-center justify-center rounded-full text-primary-600 transition-colors hover:bg-primary-50 disabled:opacity-50 dark:text-primary-400 dark:hover:bg-primary-500/10"
    :class="size === 'sm' ? 'h-5 w-5' : 'h-6 w-6'"
    :disabled="sending"
    aria-label="Call from your phone"
    title="Call from your phone"
    @click.stop="send"
  >
    <i class="pi pi-phone" :class="size === 'sm' ? 'text-sm' : ''" aria-hidden="true" />
  </button>
</template>
