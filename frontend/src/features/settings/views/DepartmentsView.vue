<script setup>
import { onMounted, ref } from 'vue'
import Button from 'primevue/button'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import DepartmentFormModal from '@/features/settings/components/DepartmentFormModal.vue'
import { useDepartmentsStore } from '@/features/settings/departmentsStore'
import { confirmAction } from '@/composables/useConfirm'
import { useRefreshable } from '@/composables/useRefreshRegistry'

const store = useDepartmentsStore()

const modalOpen = ref(false)
const modalDept = ref(null)

onMounted(() => store.fetch())
useRefreshable(() => store.fetch()) // pull-to-refresh + reconnect self-heal

function openCreate() {
  modalDept.value = null
  modalOpen.value = true
}

function openEdit(dept) {
  modalDept.value = dept
  modalOpen.value = true
}

async function onSave(payload) {
  try {
    if (modalDept.value) await store.update(modalDept.value.id, payload)
    else await store.create(payload)
    modalOpen.value = false
  } catch {
    /* error surfaced via store.error toast */
  }
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
    <PageHeader title="Departments" subtitle="Organisational units you can assign users to.">
      <template #actions>
        <Button label="Add department" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <SectionCard flush>
      <EmptyState
        v-if="!store.items.length"
        icon="pi pi-sitemap"
        title="No departments yet"
        body="Add your first organisational unit."
      />
      <ul v-else class="divide-y divide-line">
        <li
          v-for="dept in store.items"
          :key="dept.id"
          class="flex items-center gap-3 px-4 py-3 sm:px-5"
        >
          <span
            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-highlight text-primary"
          >
            <i class="pi pi-sitemap text-sm" aria-hidden="true" />
          </span>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-ink">{{ dept.name }}</p>
            <p class="truncate text-xs text-mute">
              {{ dept.slug }} · {{ dept.users_count ?? 0 }} users
            </p>
          </div>
          <Button
            icon="pi pi-pencil"
            text
            rounded
            size="small"
            severity="secondary"
            aria-label="Edit department"
            @click="openEdit(dept)"
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
        </li>
      </ul>
    </SectionCard>

    <DepartmentFormModal
      v-if="modalOpen"
      :department="modalDept"
      :saving="store.saving"
      @save="onSave"
      @close="modalOpen = false"
    />
  </div>
</template>
