<script setup>
import Button from 'primevue/button'
import ToggleSwitch from 'primevue/toggleswitch'

// One row in the Lists item manager: a read-only display of the option (label +
// machine key), with reorder / activate / edit actions. Editing happens in a
// modal (parent owns it), so there are no inline inputs here.
defineProps({
  item: { type: Object, required: true },
  isFirst: { type: Boolean, default: false },
  isLast: { type: Boolean, default: false },
})
const emit = defineEmits(['edit', 'toggle', 'move'])
</script>

<template>
  <div
    class="flex items-center gap-3 rounded-xl border border-line px-3 py-2.5 transition-colors hover:border-primary/30"
    :class="{ 'opacity-60': !item.is_active }"
  >
    <div class="flex flex-col gap-0.5">
      <Button
        icon="pi pi-chevron-up"
        text
        rounded
        size="small"
        severity="secondary"
        class="!h-6 !w-6"
        :disabled="isFirst"
        aria-label="Move up"
        @click="emit('move', -1)"
      />
      <Button
        icon="pi pi-chevron-down"
        text
        rounded
        size="small"
        severity="secondary"
        class="!h-6 !w-6"
        :disabled="isLast"
        aria-label="Move down"
        @click="emit('move', 1)"
      />
    </div>

    <i
      v-if="item.meta?.icon"
      :class="item.meta.icon"
      class="shrink-0 text-base text-mute"
      aria-hidden="true"
    />

    <div class="min-w-0 flex-1">
      <p class="truncate text-sm font-medium text-ink">{{ item.label }}</p>
      <code class="text-xs text-mute">{{ item.value }}</code>
    </div>

    <ToggleSwitch
      :model-value="Boolean(item.is_active)"
      :aria-label="item.is_active ? 'Deactivate item' : 'Activate item'"
      @update:model-value="emit('toggle')"
    />
    <Button
      icon="pi pi-pencil"
      text
      rounded
      size="small"
      severity="secondary"
      aria-label="Edit item"
      @click="emit('edit')"
    />
  </div>
</template>
