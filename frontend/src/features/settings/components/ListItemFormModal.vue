<script setup>
import { computed, ref } from 'vue'
import Button from 'primevue/button'
import ToggleSwitch from 'primevue/toggleswitch'
import BaseModal from '@/components/base/BaseModal.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import { LIST_ITEM_ICONS } from '@/features/settings/listItemIcons'

// Add / edit one dynamic-list option. The machine value is never typed: on
// create the backend derives it from the label (we only preview it here); on
// edit it is immutable, so we send the label (and active flag) only.
const props = defineProps({
  item: { type: Object, default: null }, // null = creating
  saving: { type: Boolean, default: false },
})
const emit = defineEmits(['save', 'close'])

const isEdit = computed(() => Boolean(props.item))
const label = ref(props.item?.label ?? '')
const isActive = ref(props.item ? Boolean(props.item.is_active) : true)
const icon = ref(props.item?.meta?.icon ?? '')

// The picker previews each choice (BaseSelect renders an option's `icon` inline).
const iconOptions = LIST_ITEM_ICONS.map((o) => ({ value: o.value, label: o.label, icon: o.value }))

// Mirror the backend slug (Str::slug($label, '_')) for a live preview only.
const previewKey = computed(() =>
  label.value
    .trim()
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '') // strip accents
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, ''),
)

function submit() {
  if (!label.value.trim()) return
  // Preserve any other meta keys (colour, weight…) the item already carries.
  const meta = { ...(props.item?.meta ?? {}) }
  if (icon.value) meta.icon = icon.value
  else delete meta.icon
  emit('save', {
    label: label.value.trim(),
    is_active: isActive.value,
    meta: Object.keys(meta).length ? meta : null,
  })
}
</script>

<template>
  <BaseModal :title="isEdit ? 'Edit item' : 'Add item'" size="max-w-md" @close="emit('close')">
    <form class="space-y-4" @submit.prevent="submit">
      <BaseInput v-model="label" label="Label" required placeholder="e.g. Cheque" />

      <p class="text-xs text-mute">
        <template v-if="isEdit">
          Key:
          <code class="rounded bg-surface-100 px-1 dark:bg-surface-800">{{ item.value }}</code>
          — fixed, so existing records keep working.
        </template>
        <template v-else-if="previewKey">
          Key generated automatically:
          <code class="rounded bg-surface-100 px-1 dark:bg-surface-800">{{ previewKey }}</code>
        </template>
        <template v-else>The key is generated automatically from the label.</template>
      </p>

      <BaseSelect
        v-model="icon"
        label="Icon"
        placeholder="No icon"
        :options="iconOptions"
        :searchable="true"
      />

      <label class="flex cursor-pointer items-center gap-2 text-sm text-ink">
        <ToggleSwitch v-model="isActive" />
        Active (shown in dropdowns)
      </label>

      <div class="flex justify-end gap-2 pt-2">
        <Button type="button" label="Cancel" severity="secondary" outlined @click="emit('close')" />
        <Button
          type="submit"
          :label="isEdit ? 'Save changes' : 'Add item'"
          icon="pi pi-check"
          :loading="saving"
          :disabled="!label.trim()"
        />
      </div>
    </form>
  </BaseModal>
</template>
