<script setup>
// Shown when adding a client is blocked because the phone already exists. The
// message (from the server) is either "already in your list" or "a request was
// sent to a supervisor" — never who owns it, so no one can be poached.
import BaseModal from '@/components/base/BaseModal.vue'
import Button from 'primevue/button'

defineProps({ notice: { type: Object, required: true } })
defineEmits(['close'])
</script>

<template>
  <BaseModal title="This phone already exists" size="max-w-md" @close="$emit('close')">
    <div class="flex items-start gap-3">
      <span
        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-warning/10 text-warning"
      >
        <i class="pi pi-exclamation-triangle" aria-hidden="true" />
      </span>
      <p class="text-sm text-ink">{{ notice.message }}</p>
    </div>
    <div class="mt-5 flex justify-end">
      <Button label="OK" @click="$emit('close')" />
    </div>
  </BaseModal>
</template>
