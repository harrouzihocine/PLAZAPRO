<script setup>
import { onMounted, ref } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import BaseInput from '@/components/base/BaseInput.vue'
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
  <div class="space-y-4">
    <div>
      <h1 class="text-xl font-semibold">Departments</h1>
      <p class="opacity-70">Organisational units you can assign users to.</p>
    </div>


    <BaseCard>
      <div class="space-y-2">
        <div
          v-for="dept in store.items"
          :key="dept.id"
          class="flex flex-col gap-2 rounded-token border border-border p-2 sm:flex-row sm:items-center"
        >
          <div class="flex-1">
            <BaseInput
              v-if="editingId === dept.id"
              v-model="editName"
              aria-label="Department name"
              @keyup.enter="saveEdit(dept)"
            />
            <template v-else>
              <span class="font-medium">{{ dept.name }}</span>
              <span class="ml-2 text-xs opacity-60">
                {{ dept.slug }} · {{ dept.users_count ?? 0 }} users
              </span>
            </template>
          </div>
          <div class="flex items-center gap-1">
            <template v-if="editingId === dept.id">
              <BaseButton @click="saveEdit(dept)">Save</BaseButton>
              <BaseButton variant="ghost" @click="editingId = null">Cancel</BaseButton>
            </template>
            <template v-else>
              <BaseButton variant="ghost" @click="startEdit(dept)">Edit</BaseButton>
              <BaseButton variant="ghost" @click="remove(dept)">Remove</BaseButton>
            </template>
          </div>
        </div>
        <p v-if="!store.items.length" class="py-4 text-center text-sm opacity-60">
          No departments yet.
        </p>
      </div>

      <form
        class="mt-4 flex flex-col gap-2 border-t border-border pt-4 sm:flex-row"
        @submit.prevent="add"
      >
        <BaseInput v-model="newName" label="New department" class="flex-1" />
        <div class="flex items-end">
          <BaseButton type="submit" :disabled="store.saving">Add</BaseButton>
        </div>
      </form>
    </BaseCard>
  </div>
</template>
