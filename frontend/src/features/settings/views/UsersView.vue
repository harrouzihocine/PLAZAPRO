<script setup>
import { onMounted, reactive, ref } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import { useUsersStore } from '@/features/settings/usersStore'

const store = useUsersStore()

const selectClass =
  'w-full rounded-token border border-border bg-bg px-3 py-2 min-h-[44px] text-ink outline-none focus:border-primary'

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

function cancelUser(user) {
  if (window.confirm(`Cancel user "${user.name}"? The record is kept but marked cancelled.`)) {
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

    <p v-if="store.error" class="text-sm text-danger">{{ store.error }}</p>

    <!-- Filters -->
    <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
      <select v-model="store.filters.role_id" :class="selectClass" aria-label="Filter by role" @change="store.fetch()">
        <option value="">All roles</option>
        <option v-for="r in store.roles" :key="r.id" :value="r.id">{{ r.name }}</option>
      </select>
      <select
        v-model="store.filters.department_id"
        :class="selectClass"
        aria-label="Filter by department"
        @change="store.fetch()"
      >
        <option value="">All departments</option>
        <option v-for="d in store.departments" :key="d.id" :value="d.id">{{ d.name }}</option>
      </select>
      <select v-model="store.filters.is_active" :class="selectClass" aria-label="Filter by state" @change="store.fetch()">
        <option value="">Any state</option>
        <option value="1">Active</option>
        <option value="0">Inactive</option>
      </select>
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

          <label class="block">
            <span class="mb-1 block text-sm">Role</span>
            <select v-model="form.role_id" :class="selectClass" aria-label="Role">
              <option value="" disabled>Select a role</option>
              <option v-for="r in store.roles" :key="r.id" :value="r.id">{{ r.name }}</option>
            </select>
          </label>

          <label class="block">
            <span class="mb-1 block text-sm">Department</span>
            <select v-model="form.department_id" :class="selectClass" aria-label="Department">
              <option value="">None</option>
              <option v-for="d in store.departments" :key="d.id" :value="d.id">{{ d.name }}</option>
            </select>
          </label>

          <BaseInput v-model="form.phone" label="Phone" />

          <div class="flex gap-2 pt-2">
            <BaseButton type="submit" :disabled="store.saving">Save</BaseButton>
            <BaseButton type="button" variant="ghost" @click="drawerOpen = false">Cancel</BaseButton>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
