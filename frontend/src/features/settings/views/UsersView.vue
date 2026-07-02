<script setup>
import { onMounted, reactive, ref } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import BasePhoneInput from '@/components/base/BasePhoneInput.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import { useUsersStore } from '@/features/settings/usersStore'
import { confirmAction } from '@/composables/useConfirm'

const store = useUsersStore()

const drawerOpen = ref(false)
const editingId = ref(null) // null = creating
const form = reactive({
  name: '',
  email: '',
  password: '',
  role_id: '',
  department_id: '',
  phone: '',
})

onMounted(() => store.fetch())

function openCreate() {
  editingId.value = null
  Object.assign(form, { name: '', email: '', password: '', role_id: '', department_id: '', phone: '' })
  drawerOpen.value = true
}

function openEdit(user) {
  editingId.value = user.id
  Object.assign(form, {
    name: user.name,
    email: user.email,
    password: '',
    role_id: user.role?.id ?? '',
    department_id: user.department?.id ?? '',
    phone: user.phone ?? '',
  })
  drawerOpen.value = true
}

async function save() {
  if (!form.name.trim() || !form.email.trim() || !form.role_id) return
  const payload = {
    name: form.name.trim(),
    email: form.email.trim(),
    role_id: form.role_id,
    department_id: form.department_id || null,
    phone: form.phone.trim() || null,
  }
  // Password is only sent when set (required on create, optional on edit).
  if (form.password) payload.password = form.password

  try {
    if (editingId.value) await store.update(editingId.value, payload)
    else await store.create(payload)
    drawerOpen.value = false
  } catch {
    /* error surfaced via store.error */
  }
}

function toggleActive(user) {
  store.setActive(user.id, !user.is_active)
}

async function cancelUser(user) {
  if (
    await confirmAction({
      title: `Cancel user "${user.name}"?`,
      text: 'The record is kept but marked cancelled.',
      confirmText: 'Cancel user',
      danger: true,
    })
  ) {
    store.cancel(user.id)
  }
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h1 class="text-xl font-semibold">Users</h1>
        <p class="opacity-70">People with access. Exactly one role each.</p>
      </div>
      <BaseButton @click="openCreate">New user</BaseButton>
    </div>


    <!-- Filters -->
    <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
      <BaseSelect
        v-model="store.filters.role_id"
        aria-label="Filter by role"
        placeholder="All roles"
        :options="store.roles.map((r) => ({ value: r.id, label: r.name }))"
        @change="store.fetch()"
      />
      <BaseSelect
        v-model="store.filters.department_id"
        aria-label="Filter by department"
        placeholder="All departments"
        :options="store.departments.map((d) => ({ value: d.id, label: d.name }))"
        @change="store.fetch()"
      />
      <BaseSelect
        v-model="store.filters.is_active"
        aria-label="Filter by state"
        placeholder="Any state"
        :options="[{ value: '1', label: 'Active' }, { value: '0', label: 'Inactive' }]"
        @change="store.fetch()"
      />
    </div>

    <BaseCard>
      <div class="space-y-2">
        <div
          v-for="user in store.items"
          :key="user.id"
          class="flex flex-col gap-2 rounded-token border border-border p-2 sm:flex-row sm:items-center"
        >
          <div class="flex-1">
            <span class="font-medium">{{ user.name }}</span>
            <span
              v-if="!user.is_active"
              class="ml-2 rounded-token bg-surface px-2 py-0.5 text-xs opacity-70"
            >
              inactive
            </span>
            <div class="text-xs opacity-60">
              {{ user.email }} · {{ user.role?.name ?? '—' }}
              <template v-if="user.department"> · {{ user.department.name }}</template>
            </div>
          </div>
          <div class="flex items-center gap-1">
            <BaseButton variant="ghost" @click="openEdit(user)">Edit</BaseButton>
            <BaseButton variant="ghost" @click="toggleActive(user)">
              {{ user.is_active ? 'Deactivate' : 'Activate' }}
            </BaseButton>
            <BaseButton variant="ghost" @click="cancelUser(user)">Remove</BaseButton>
          </div>
        </div>
        <p v-if="!store.items.length" class="py-4 text-center text-sm opacity-60">No users match.</p>
      </div>
    </BaseCard>

    <!-- Create / edit drawer -->
    <div
      v-if="drawerOpen"
      class="fixed inset-0 z-40 flex justify-end bg-black/40"
      @click.self="drawerOpen = false"
    >
      <div class="h-full w-full max-w-md overflow-y-auto bg-bg p-4 shadow-lg sm:p-6">
        <h2 class="mb-4 text-lg font-semibold">{{ editingId ? 'Edit user' : 'New user' }}</h2>
        <form class="space-y-3" @submit.prevent="save">
          <BaseInput v-model="form.name" label="Name" />
          <BaseInput v-model="form.email" label="Email" type="email" />
          <BaseInput v-model="form.password" label="Password" type="password" />
          <p v-if="editingId" class="-mt-2 text-xs opacity-60">Leave blank to keep the current password.</p>

          <BaseSelect
            v-model="form.role_id"
            label="Role"
            placeholder="Select a role"
            :clearable="false"
            :options="store.roles.map((r) => ({ value: r.id, label: r.name }))"
          />

          <BaseSelect
            v-model="form.department_id"
            label="Department"
            placeholder="None"
            :options="store.departments.map((d) => ({ value: d.id, label: d.name }))"
          />

          <BasePhoneInput v-model="form.phone" label="Phone" />

          <div class="flex gap-2 pt-2">
            <BaseButton type="submit" :disabled="store.saving">Save</BaseButton>
            <BaseButton type="button" variant="ghost" @click="drawerOpen = false">Cancel</BaseButton>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
