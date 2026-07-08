<script setup>
import { computed, reactive } from 'vue'
import Button from 'primevue/button'
import BaseModal from '@/components/base/BaseModal.vue'
import BaseInput from '@/components/base/BaseInput.vue'

// Add / edit a commune within a wilaya. Name is required; Daïra is optional.
const props = defineProps({
  commune: { type: Object, default: null }, // null = creating
  wilayaName: { type: String, default: '' },
  saving: { type: Boolean, default: false },
})
const emit = defineEmits(['save', 'close'])

const isEdit = computed(() => Boolean(props.commune))
const form = reactive({
  name: props.commune?.name ?? '',
  daira_name: props.commune?.daira_name ?? '',
})

function submit() {
  if (!form.name.trim()) return
  emit('save', { name: form.name.trim(), daira_name: form.daira_name.trim() || null })
}
</script>

<template>
  <BaseModal
    :title="isEdit ? $t('geoAdmin.editCommune') : $t('geoAdmin.addCommune')"
    size="max-w-md"
    @close="emit('close')"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <p v-if="wilayaName" class="text-xs text-mute">In {{ wilayaName }}</p>
      <BaseInput v-model="form.name" :label="$t('geo.commune')" required placeholder="Bab El Oued" />
      <BaseInput v-model="form.daira_name" :label="$t('geoAdmin.daira')" />
      <div class="flex justify-end gap-2 pt-2">
        <Button type="button" :label="$t('common.cancel')" severity="secondary" outlined @click="emit('close')" />
        <Button
          type="submit"
          :label="isEdit ? $t('chat.saveChanges') : $t('geoAdmin.addCommune')"
          icon="pi pi-check"
          :loading="saving"
          :disabled="!form.name.trim()"
        />
      </div>
    </form>
  </BaseModal>
</template>
