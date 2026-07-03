<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import BaseInput from '@/components/base/BaseInput.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import { useRolesStore } from '@/features/settings/rolesStore'
import { confirmAction } from '@/composables/useConfirm'

const store = useRolesStore()

const selectedId = ref(null) // null = editing a new-role draft
const form = reactive({ name: '', description: '', is_agent: false, permissions: [] })

onMounted(() => store.fetch())

// Group the permission catalogue by its `group` for the matrix.
const groupedPermissions = computed(() => {
  const groups = {}
  for (const p of store.permissions) {
    ;(groups[p.group || 'Other'] ??= []).push(p)
  }
  return groups
})

function selectRole(role) {
  selectedId.value = role.id
  form.name = role.name
  form.description = role.description ?? ''
  form.is_agent = role.is_agent
  form.permissions = [...(role.permissions ?? [])]
}

function startNew() {
  selectedId.value = null
  form.name = ''
  form.description = ''
  form.is_agent = false
  form.permissions = []
}

async function save() {
  if (!form.name.trim()) return
  const payload = {
    name: form.name.trim(),
    description: form.description.trim() || null,
    is_agent: form.is_agent,
    permissions: form.permissions,
  }
  try {
    if (selectedId.value) {
      await store.update(selectedId.value, payload)
    } else {
      const created = await store.create(payload)
      selectedId.value = created?.id ?? null
    }
  } catch {
    /* error surfaced via store.error */
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
    if (selectedId.value === role.id) startNew()
  }
}
</script>

<template>
  <div>
    <PageHeader
      title="Roles & permissions"
      subtitle="One role per user; the agent flag controls visit-assignment eligibility."
    />

    <div class="grid grid-cols-1 gap-5 md:grid-cols-[17rem_1fr]">
      <!-- Role list -->
      <SectionCard title="Roles" icon="pi pi-shield" flush class="self-start">
        <template #actions>
          <Button label="New" icon="pi pi-plus" text size="small" @click="startNew" />
        </template>
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
            @click="selectRole(role)"
          >
            <span class="truncate">{{ role.name }}</span>
            <span class="flex shrink-0 items-center gap-1.5 text-xs">
              <Tag v-if="role.is_agent" value="agent" severity="info" />
              <span class="num text-mute">{{ role.users_count ?? 0 }}</span>
            </span>
          </button>
        </nav>
      </SectionCard>

      <!-- Editor -->
      <SectionCard :title="selectedId ? 'Edit role' : 'New role'" icon="pi pi-pencil">
        <div class="space-y-4">
          <div class="grid gap-3 sm:grid-cols-2">
            <BaseInput v-model="form.name" label="Name" />
            <BaseInput v-model="form.description" label="Description" />
          </div>
          <label class="flex cursor-pointer items-center gap-2 text-sm text-ink">
            <Checkbox v-model="form.is_agent" binary />
            Agent role (eligible for visit assignment)
          </label>

          <div>
            <h3 class="mb-2 text-sm font-semibold text-ink">Permissions</h3>
            <div class="space-y-4">
              <fieldset v-for="(perms, group) in groupedPermissions" :key="group">
                <legend class="mb-1 text-xs font-semibold uppercase tracking-wide text-mute">
                  {{ group }}
                </legend>
                <div class="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                  <label
                    v-for="p in perms"
                    :key="p.id"
                    class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1 text-sm text-ink hover:bg-surface-50 dark:hover:bg-surface-800"
                  >
                    <Checkbox v-model="form.permissions" :value="p.id" />
                    <span :title="p.slug">{{ p.name }}</span>
                  </label>
                </div>
              </fieldset>
            </div>
          </div>

          <div class="flex items-center gap-2 border-t border-line pt-4">
            <Button
              :label="selectedId ? 'Save changes' : 'Create role'"
              icon="pi pi-check"
              :disabled="store.saving || !form.name.trim()"
              @click="save"
            />
            <Button
              v-if="selectedId"
              label="Cancel role"
              icon="pi pi-ban"
              severity="danger"
              outlined
              @click="cancelRole(store.roles.find((r) => r.id === selectedId))"
            />
          </div>
        </div>
      </SectionCard>
    </div>
  </div>
</template>
