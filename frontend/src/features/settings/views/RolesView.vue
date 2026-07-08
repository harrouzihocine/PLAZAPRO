<script setup>
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import RoleFormModal from '@/features/settings/components/RoleFormModal.vue'
import { useRolesStore } from '@/features/settings/rolesStore'
import { confirmAction } from '@/composables/useConfirm'
import { useRefreshable } from '@/composables/useRefreshRegistry'

const store = useRolesStore()

const selectedId = ref(null)
const modalOpen = ref(false)
const modalRole = ref(null) // null = creating

onMounted(async () => {
  await store.fetch()
  if (!selectedId.value && store.roles.length) selectedId.value = store.roles[0].id
})
useRefreshable(() => store.fetch()) // pull-to-refresh + reconnect self-heal

const selectedRole = computed(() => store.roles.find((r) => r.id === selectedId.value) ?? null)

// Plain-language capability summary: the role's granted permissions, resolved to
// their catalogue entries and grouped by area — so an admin can read exactly
// what the role can do without decoding a checkbox matrix.
const capabilities = computed(() => {
  const role = selectedRole.value
  if (!role) return []
  const ids = new Set(role.permissions ?? [])
  const map = {}
  for (const p of store.permissions) {
    if (ids.has(p.id)) (map[p.group || 'Other'] ??= []).push(p)
  }
  return Object.entries(map).map(([label, items]) => ({ label, items }))
})

function openCreate() {
  modalRole.value = null
  modalOpen.value = true
}
function openEdit() {
  modalRole.value = selectedRole.value
  modalOpen.value = true
}

async function onSave(payload) {
  try {
    if (modalRole.value) {
      await store.update(modalRole.value.id, payload)
    } else {
      const created = await store.create(payload)
      if (created?.id) selectedId.value = created.id
    }
    modalOpen.value = false
  } catch {
    /* error surfaced via store.error toast */
  }
}

async function cancelRole(role) {
  if (!role) return
  if (
    await confirmAction({
      title: `Cancel role "${role.name}"?`,
      text: 'The record is kept but marked cancelled.',
      confirmText: 'Cancel role',
      danger: true,
    })
  ) {
    await store.cancel(role.id)
    if (selectedId.value === role.id) selectedId.value = store.roles[0]?.id ?? null
  }
}
</script>

<template>
  <div>
    <PageHeader
      title="Roles &amp; permissions"
      subtitle="One role per user. Each permission below explains exactly what it unlocks."
    >
      <template #actions>
        <Button label="New role" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[17rem_minmax(0,1fr)]">
      <!-- Role list -->
      <SectionCard title="Roles" icon="pi pi-shield" flush class="self-start">
        <nav class="flex flex-col gap-0.5 p-2">
          <button
            v-for="role in store.roles"
            :key="role.id"
            type="button"
            class="flex items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-sm transition-colors"
            :class="
              role.id === selectedId
                ? 'bg-highlight font-semibold text-ink'
                : 'text-mute hover:bg-surface-100 hover:text-ink dark:hover:bg-surface-800'
            "
            @click="selectedId = role.id"
          >
            <span class="truncate">{{ role.name }}</span>
            <span class="flex shrink-0 items-center gap-1.5 text-xs">
              <Tag v-if="role.is_agent" value="agent" severity="info" />
              <span class="num text-mute">{{ role.users_count ?? 0 }}</span>
            </span>
          </button>
        </nav>
      </SectionCard>

      <!-- Role overview -->
      <SectionCard v-if="selectedRole">
        <template #header>
          <div class="min-w-0">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-ink">
              {{ selectedRole.name }}
              <Tag v-if="selectedRole.is_agent" value="agent" severity="info" />
            </h2>
            <p class="mt-0.5 text-xs text-mute">{{ selectedRole.users_count ?? 0 }} users</p>
          </div>
        </template>
        <template #actions>
          <Button
            icon="pi pi-pencil"
            label="Edit"
            size="small"
            severity="secondary"
            outlined
            @click="openEdit"
          />
          <Button
            icon="pi pi-ban"
            label="Cancel role"
            size="small"
            severity="danger"
            outlined
            @click="cancelRole(selectedRole)"
          />
        </template>

        <p v-if="selectedRole.description" class="text-sm text-ink">
          {{ selectedRole.description }}
        </p>
        <p v-else class="text-sm italic text-mute">No description yet.</p>

        <div class="mt-5">
          <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-mute">
            What this role can do
          </h3>

          <EmptyState
            v-if="!capabilities.length"
            icon="pi pi-lock"
            title="No permissions granted"
            body="This role can sign in but can't do anything yet. Use Edit to grant permissions."
          />

          <div v-else class="space-y-4">
            <div v-for="group in capabilities" :key="group.label">
              <p class="mb-1.5 text-xs font-semibold text-ink">{{ group.label }}</p>
              <ul class="space-y-1.5">
                <li v-for="p in group.items" :key="p.id" class="flex items-start gap-2 text-sm">
                  <i class="pi pi-check-circle mt-0.5 text-success" aria-hidden="true" />
                  <span class="min-w-0">
                    <span class="text-ink">{{ p.name }}</span>
                    <span v-if="p.description" class="text-mute"> — {{ p.description }}</span>
                  </span>
                </li>
              </ul>
            </div>
          </div>
        </div>
      </SectionCard>

      <SectionCard v-else>
        <EmptyState
          icon="pi pi-shield"
          title="Select a role"
          body="Pick one on the left to see what it can do, or create a new role."
        />
      </SectionCard>
    </div>

    <RoleFormModal
      v-if="modalOpen"
      :role="modalRole"
      :permissions="store.permissions"
      :saving="store.saving"
      @save="onSave"
      @close="modalOpen = false"
    />
  </div>
</template>
