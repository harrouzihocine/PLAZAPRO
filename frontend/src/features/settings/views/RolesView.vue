<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import BaseInput from '@/components/base/BaseInput.vue'
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

function togglePermission(id) {
  const i = form.permissions.indexOf(id)
  if (i === -1) form.permissions.push(id)
  else form.permissions.splice(i, 1)
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
  <div class="space-y-4">
    <div>
      <h1 class="text-xl font-semibold">Roles &amp; permissions</h1>
      <p class="opacity-70">
        One role per user; the agent flag controls visit-assignment eligibility.
      </p>
    </div>


    <div class="grid grid-cols-1 gap-4 md:grid-cols-[16rem_1fr]">
      <!-- Role list -->
      <BaseCard>
        <div class="mb-2 flex items-center justify-between">
          <h2 class="font-medium">Roles</h2>
          <BaseButton variant="ghost" @click="startNew">+ New</BaseButton>
        </div>
        <nav class="flex flex-col gap-1">
          <button
            v-for="role in store.roles"
            :key="role.id"
            class="flex items-center justify-between rounded-token px-3 py-2 text-left hover:bg-bg"
            :class="{ 'bg-bg text-primary': role.id === selectedId }"
            @click="selectRole(role)"
          >
            <span>{{ role.name }}</span>
            <span class="flex items-center gap-1 text-xs opacity-60">
              <span v-if="role.is_agent" title="Agent role">🧭</span>
              {{ role.users_count ?? 0 }}
            </span>
          </button>
        </nav>
      </BaseCard>

      <!-- Editor -->
      <BaseCard>
        <h2 class="mb-3 font-medium">{{ selectedId ? 'Edit role' : 'New role' }}</h2>
        <div class="space-y-3">
          <BaseInput v-model="form.name" label="Name" />
          <BaseInput v-model="form.description" label="Description" />
          <label class="flex items-center gap-2">
            <input v-model="form.is_agent" type="checkbox" class="h-4 w-4" />
            <span class="text-sm">Agent role (eligible for visit assignment)</span>
          </label>

          <div>
            <h3 class="mb-1 text-sm font-medium">Permissions</h3>
            <div class="space-y-3">
              <fieldset v-for="(perms, group) in groupedPermissions" :key="group">
                <legend class="text-xs uppercase tracking-wide opacity-60">{{ group }}</legend>
                <div class="grid grid-cols-1 gap-1 sm:grid-cols-2">
                  <label v-for="p in perms" :key="p.id" class="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      class="h-4 w-4"
                      :checked="form.permissions.includes(p.id)"
                      @change="togglePermission(p.id)"
                    />
                    <span :title="p.slug">{{ p.name }}</span>
                  </label>
                </div>
              </fieldset>
            </div>
          </div>

          <div class="flex items-center gap-2 border-t border-border pt-3">
            <BaseButton :disabled="store.saving || !form.name.trim()" @click="save">
              {{ selectedId ? 'Save changes' : 'Create role' }}
            </BaseButton>
            <BaseButton
              v-if="selectedId"
              variant="ghost"
              @click="cancelRole(store.roles.find((r) => r.id === selectedId))"
            >
              Cancel role
            </BaseButton>
          </div>
        </div>
      </BaseCard>
    </div>
  </div>
</template>
