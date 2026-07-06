<script setup>
import { computed, reactive } from 'vue'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import ToggleSwitch from 'primevue/toggleswitch'
import BaseModal from '@/components/base/BaseModal.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'

// Add / edit a role. The permission catalogue is passed in; each permission
// shows its plain-language description so the admin knows exactly what they
// grant. `is_agent` controls visit-assignment eligibility.
const props = defineProps({
  role: { type: Object, default: null }, // null = creating
  permissions: { type: Array, default: () => [] },
  saving: { type: Boolean, default: false },
})
const emit = defineEmits(['save', 'close'])

const isEdit = computed(() => Boolean(props.role))
const form = reactive({
  name: props.role?.name ?? '',
  description: props.role?.description ?? '',
  is_agent: props.role ? Boolean(props.role.is_agent) : false,
  permissions: [...(props.role?.permissions ?? [])],
})

// Group the catalogue by its `group` for the matrix.
const groups = computed(() => {
  const map = {}
  for (const p of props.permissions) (map[p.group || 'Other'] ??= []).push(p)
  return Object.entries(map).map(([label, items]) => ({ label, items }))
})

function groupState(items) {
  const on = items.filter((p) => form.permissions.includes(p.id)).length
  return { on, all: on === items.length, some: on > 0 && on < items.length }
}

function toggleGroup(items, checked) {
  const ids = items.map((p) => p.id)
  if (checked) form.permissions = [...new Set([...form.permissions, ...ids])]
  else form.permissions = form.permissions.filter((id) => !ids.includes(id))
}

function submit() {
  if (!form.name.trim()) return
  emit('save', {
    name: form.name.trim(),
    description: form.description.trim() || null,
    is_agent: form.is_agent,
    permissions: form.permissions,
  })
}
</script>

<template>
  <BaseModal :title="isEdit ? 'Edit role' : 'New role'" size="max-w-3xl" @close="emit('close')">
    <form class="space-y-5" @submit.prevent="submit">
      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.name" label="Role name" required placeholder="e.g. Senior Agent" />
        <label
          class="flex cursor-pointer items-center justify-between gap-3 rounded-xl border border-line px-3.5 py-2.5"
        >
          <span class="text-sm">
            <span class="block font-medium text-ink">Agent role</span>
            <span class="block text-xs text-mute">Eligible to be assigned visits</span>
          </span>
          <ToggleSwitch v-model="form.is_agent" />
        </label>
      </div>

      <BaseTextarea
        v-model="form.description"
        label="Description"
        :rows="2"
        placeholder="Plain-language summary of what this role is for."
      />

      <div>
        <div class="mb-2 flex items-center justify-between">
          <h3 class="text-sm font-semibold text-ink">Permissions</h3>
          <span class="text-xs text-mute">{{ form.permissions.length }} selected</span>
        </div>

        <div class="max-h-[46vh] space-y-3 overflow-y-auto pr-1">
          <fieldset
            v-for="group in groups"
            :key="group.label"
            class="overflow-hidden rounded-xl border border-line"
          >
            <div class="flex items-center justify-between gap-2 bg-surface-50 px-3 py-2 dark:bg-surface-800/50">
              <legend class="text-xs font-semibold uppercase tracking-wide text-mute">
                {{ group.label }}
              </legend>
              <label class="flex cursor-pointer items-center gap-1.5 text-xs text-mute">
                <Checkbox
                  :model-value="groupState(group.items).all"
                  :indeterminate="groupState(group.items).some"
                  binary
                  @update:model-value="toggleGroup(group.items, $event)"
                />
                Select all
              </label>
            </div>
            <div class="divide-y divide-line">
              <label
                v-for="p in group.items"
                :key="p.id"
                class="flex cursor-pointer items-start gap-3 px-3 py-2.5 transition-colors hover:bg-surface-50 dark:hover:bg-surface-800/40"
              >
                <Checkbox v-model="form.permissions" :value="p.id" class="mt-0.5" />
                <span class="min-w-0">
                  <span class="block text-sm font-medium text-ink">{{ p.name }}</span>
                  <span v-if="p.description" class="block text-xs text-mute">{{ p.description }}</span>
                </span>
              </label>
            </div>
          </fieldset>
        </div>
      </div>

      <div class="flex justify-end gap-2 border-t border-line pt-4">
        <Button type="button" label="Cancel" severity="secondary" outlined @click="emit('close')" />
        <Button
          type="submit"
          :label="isEdit ? 'Save changes' : 'Create role'"
          icon="pi pi-check"
          :loading="saving"
          :disabled="!form.name.trim()"
        />
      </div>
    </form>
  </BaseModal>
</template>
