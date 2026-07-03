<script setup>
import { computed } from 'vue'
import Tag from 'primevue/tag'
import { statusMeta } from '@/utils/status'

// Every domain state badge in the app. Pass the raw API value; the label /
// colour / icon come from the shared status map so all pages agree.
const props = defineProps({
  value: { type: String, default: null },
  label: { type: String, default: null }, // optional label override
  icon: { type: Boolean, default: true },
})

const meta = computed(() => statusMeta(props.value))
</script>

<template>
  <Tag
    v-if="value"
    :severity="meta.severity"
    :icon="icon ? meta.icon : null"
    :value="label ?? meta.label"
    class="whitespace-nowrap"
  />
</template>
