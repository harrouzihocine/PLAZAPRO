<script setup>
import { onMounted, ref } from 'vue'
import Button from 'primevue/button'
import BaseInput from '@/components/base/BaseInput.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import { useDepartmentsStore } from '@/features/settings/departmentsStore'
import { confirmAction } from '@/composables/useConfirm'

const store = useDepartmentsStore()

const newName = ref('')
const editingId = ref(null)
const editName = ref('')

onMounted(() => store.fetch())

async function add() {
  if (!newName.value.trim()) return
  try {
    await store.create({ name: newName.value.trim() })
    newName.value = ''
  } catch {
    /* error surfaced via store.error */
  }
}

function startEdit(dept) {
  editingId.value = dept.id
  editName.value = dept.name
}

async function saveEdit(dept) {
  if (editName.value.trim() && editName.value.trim() !== dept.name) {
    await store.update(dept.id, { name: editName.value.trim() })
  }
  editingId.value = null
}

async function remove(dept) {
  if (
    await confirmAction({
      title: `Cancel department "${dept.name}"?`,
      text: 'The record is kept but marked cancelled.',
      confirmText: 'Cancel department',
      danger: true,
    })
  ) {
    store.cancel(dept.id)
  }
}
</script>

<template>
  <div>
    <PageHeader title="Departments" subtitle="Organisational units you can assign users to." />

    <SectionCard>
      <div class="space-y-2">
        <div
          v-for="dept in store.items"
          :key="dept.id"
          class="flex flex-col gap-2 rounded-xl border border-line p-2.5 sm:flex-row sm:items-center"
        >
          <div class="flex-1">
            <BaseInput
              v-if="editingId === dept.id"
              v-model="editName"
              aria-label="Department name"
              @keyup.enter="saveEdit(dept)"
            />
            <template v-else>
              <span class="font-medium text-ink">{{ dept.name }}</span>
              <span class="ml-2 text-xs text-mute">
                {{ dept.slug }} · {{ dept.users_count ?? 0 }} users
              </span>
            </template>
          </div>
          <div class="flex items-center gap-1">
            <template v-if="editingId === dept.id">
              <Button label="Save" icon="pi pi-check" size="small" @click="saveEdit(dept)" />
              <Button
                label="Cancel"
                size="small"
                severity="secondary"
                outlined
                @click="editingId = null"
              />
            </template>
            <template v-else>
              <Button
                icon="pi pi-pencil"
                text
                rounded
                size="small"
                severity="secondary"
                aria-label="Edit department"
                @click="startEdit(dept)"
              />
              <Button
                icon="pi pi-ban"
                text
                rounded
                size="small"
                severity="danger"
                aria-label="Cancel department"
                @click="remove(dept)"
              />
            </template>
          </div>
        </div>
        <p v-if="!store.items.length" class="py-4 text-center text-sm text-mute">
          No departments yet.
        </p>
      </div>

      <form
        class="mt-4 flex flex-col gap-2 border-t border-line pt-4 sm:flex-row"
        @submit.prevent="add"
      >
        <BaseInput v-model="newName" label="New department" class="flex-1" />
        <div class="flex items-end">
          <Button type="submit" label="Add" icon="pi pi-plus" :loading="store.saving" />
        </div>
      </form>
    </SectionCard>
  </div>
</template>
