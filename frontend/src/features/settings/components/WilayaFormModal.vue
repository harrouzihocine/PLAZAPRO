<script setup>
import { computed, reactive } from 'vue'
import Button from 'primevue/button'
import BaseModal from '@/components/base/BaseModal.vue'
import BaseInput from '@/components/base/BaseInput.vue'

// Add / edit a wilaya. Code is the official wilaya number (typed by hand) and
// Name is the wilaya name.
const props = defineProps({
  wilaya: { type: Object, default: null }, // null = creating
  saving: { type: Boolean, default: false },
})
const emit = defineEmits(['save', 'close'])

const isEdit = computed(() => Boolean(props.wilaya))
const form = reactive({
  code: props.wilaya?.code != null ? String(props.wilaya.code) : '',
  name: props.wilaya?.name ?? '',
})

function submit() {
  if (!form.code.trim() || !form.name.trim()) return
  emit('save', { code: form.code.trim(), name: form.name.trim() })
}
</script>

<template>
  <BaseModal :title="isEdit ? 'Edit wilaya' : 'Add wilaya'" size="max-w-md" @close="emit('close')">
    <form class="space-y-4" @submit.prevent="submit">
      <div class="flex gap-3">
        <BaseInput v-model="form.code" label="Code" required placeholder="16" class="w-24" />
        <BaseInput v-model="form.name" label="Name" required placeholder="e.g. Alger" class="flex-1" />
      </div>
      <div class="flex justify-end gap-2 pt-2">
        <Button type="button" label="Cancel" severity="secondary" outlined @click="emit('close')" />
        <Button
          type="submit"
          :label="isEdit ? 'Save changes' : 'Add wilaya'"
          icon="pi pi-check"
          :loading="saving"
          :disabled="!form.code.trim() || !form.name.trim()"
        />
      </div>
    </form>
  </BaseModal>
</template>
