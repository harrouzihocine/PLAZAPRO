<script setup>
import { ref } from 'vue'
import Popover from 'primevue/popover'
import OfficeInviteActions from '@/features/pipeline/components/OfficeInviteActions.vue'

// A one-tap "invite the client to the office" action, offered on planned office
// visits (dashboard work items + the client-file timeline) and standalone on
// the client file. The icon opens a popover that sends the invitation over
// WhatsApp and can copy the office address (OfficeInviteActions) — no backend
// send.
defineProps({
  clientName: { type: String, default: '' },
  phone: { type: String, default: '' },
  // The scheduled office-visit moment (ISO / Date); omitted → a generic invite.
  scheduledAt: { type: [String, Number, Date], default: null },
  // 'sm' matches the compact icons in dashboard rows / contact bars; 'md' the
  // timeline block.
  size: { type: String, default: 'md' },
})

const pop = ref(null)
function toggle(e) {
  pop.value?.toggle(e)
}
</script>

<template>
  <span>
    <button
      type="button"
      class="inline-flex shrink-0 items-center justify-center rounded-full text-primary-600 transition-colors hover:bg-primary-50 dark:text-primary-400 dark:hover:bg-primary-500/10"
      :class="size === 'sm' ? 'h-6 w-6' : 'h-8 w-8'"
      :title="$t('officeInvite.action')"
      :aria-label="clientName ? $t('officeInvite.ariaSend', { name: clientName }) : $t('officeInvite.action')"
      @click.stop.prevent="toggle"
    >
      <i class="pi pi-send" :class="size === 'sm' ? 'text-sm' : ''" aria-hidden="true" />
    </button>

    <Popover ref="pop" class="w-56 max-w-[92vw]">
      <OfficeInviteActions
        :client-name="clientName"
        :phone="phone"
        :scheduled-at="scheduledAt"
        @done="pop?.hide()"
      />
    </Popover>
  </span>
</template>
