<script setup>
import { computed, ref } from 'vue'
import Button from 'primevue/button'
import BaseModal from '@/components/base/BaseModal.vue'
import BaseInput from '@/components/base/BaseInput.vue'

// Add / edit a department. Only a name — the slug is derived server-side.
const props = defineProps({
  department: { type: Object, default: null }, // null = creating
  saving: { type: Boolean, default: false },
})
const emit = defineEmits(['save', 'close'])

const isEdit = computed(() => Boolean(props.department))
const name = ref(props.department?.name ?? '')

function submit() {
  if (!name.value.trim()) return
  emit('save', { name: name.value.trim() })
}
</script>

<template>
  <BaseModal
    :title="isEdit ? 'Edit department' : 'Add department'"
    size="max-w-md"
    @close="emit('close')"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <BaseInput v-model="name" label="Name" required placeholder="e.g. Sales" />
      <div class="flex justify-end gap-2 pt-2">
        <Button type="button" label="Cancel" severity="secondary" outlined @click="emit('close')" />
        <Button
          type="submit"
          :label="isEdit ? 'Save changes' : 'Add department'"
          icon="pi pi-check"
          :loading="saving"
          :disabled="!name.trim()"
        />
      </div>
    </form>
  </BaseModal>
</template>
