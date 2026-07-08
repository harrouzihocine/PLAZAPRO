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

// Per-language display labels (optional; the base label is the fallback).
// Dropdowns app-wide show translations[locale] ?? label.
const translations = ref({
  en: props.item?.label_translations?.en ?? '',
  fr: props.item?.label_translations?.fr ?? '',
  ar: props.item?.label_translations?.ar ?? '',
})

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
  const labels = Object.fromEntries(
    Object.entries(translations.value)
      .map(([k, v]) => [k, v.trim()])
      .filter(([, v]) => v !== ''),
  )
  emit('save', {
    label: label.value.trim(),
    label_translations: Object.keys(labels).length ? labels : null,
    is_active: isActive.value,
    meta: Object.keys(meta).length ? meta : null,
  })
}
</script>

<template>
  <BaseModal :title="isEdit ? $t('settings.editItem') : $t('settings.addItem')" size="max-w-md" @close="emit('close')">
    <form class="space-y-4" @submit.prevent="submit">
      <BaseInput v-model="label" :label="$t('settings.itemLabel')" required :placeholder="$t('settings.itemLabelPlaceholder')" />

      <p class="text-xs text-mute">
        <template v-if="isEdit">
          {{ $t('settings.keyLabel') }}
          <code class="rounded bg-surface-100 px-1 dark:bg-surface-800">{{ item.value }}</code>
          — {{ $t('settings.keyFixed') }}
        </template>
        <template v-else-if="previewKey">
          {{ $t('settings.keyGenerated') }}
          <code class="rounded bg-surface-100 px-1 dark:bg-surface-800">{{ previewKey }}</code>
        </template>
        <template v-else>{{ $t('settings.keyAuto') }}</template>
      </p>

      <!-- Per-language labels: what each UI language shows for this option. -->
      <fieldset class="space-y-3 rounded-xl border border-line p-3">
        <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-mute">
          {{ $t('settings.translations') }}
        </legend>
        <p class="text-xs text-mute">{{ $t('settings.translationsHint') }}</p>
        <BaseInput v-model="translations.en" label="English" dir="ltr" />
        <BaseInput v-model="translations.fr" label="Français" dir="ltr" />
        <BaseInput v-model="translations.ar" label="العربية" dir="rtl" />
      </fieldset>

      <BaseSelect
        v-model="icon"
:label="$t('settings.icon')"
        :placeholder="$t('settings.noIcon')"
        :options="iconOptions"
        :searchable="true"
      />

      <label class="flex cursor-pointer items-center gap-2 text-sm text-ink">
        <ToggleSwitch v-model="isActive" />
        {{ $t('settings.activeShown') }}
      </label>

      <div class="flex justify-end gap-2 pt-2">
        <Button type="button" :label="$t('common.cancel')" severity="secondary" outlined @click="emit('close')" />
        <Button
          type="submit"
          :label="isEdit ? $t('chat.saveChanges') : $t('settings.addItem')"
          icon="pi pi-check"
          :loading="saving"
          :disabled="!label.trim()"
        />
      </div>
    </form>
  </BaseModal>
</template>
