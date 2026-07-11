<script setup>
import { computed } from 'vue'
import Dialog from 'primevue/dialog'
import LeadForm from './LeadForm.vue'

const props = defineProps({
  open: { type: Boolean, default: false },
  type: { type: String, default: 'interest' },
  project: { type: Object, default: null },
  unit: { type: Object, default: null },
})

const emit = defineEmits(['update:open'])

const visible = computed({
  get: () => props.open,
  set: (v) => emit('update:open', v),
})
</script>

<template>
  <Dialog
    v-model:visible="visible"
    modal
    dismissable-mask
    :header="$t(`showcase.lead.title_${type}`)"
    class="w-[26rem] max-w-[calc(100vw-2rem)]"
  >
    <!-- key: a fresh form per open/type — success state never leaks across uses -->
    <LeadForm :key="`${type}-${unit?.id ?? 'p'}-${open}`" :type="type" :project="project" :unit="unit" />
  </Dialog>
</template>
