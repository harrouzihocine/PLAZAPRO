<script setup>
import { onMounted, reactive, ref } from 'vue'
import Avatar from 'primevue/avatar'
import Button from 'primevue/button'
import Drawer from 'primevue/drawer'
import Tag from 'primevue/tag'
import BaseInput from '@/components/base/BaseInput.vue'
import BasePhoneInput from '@/components/base/BasePhoneInput.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useUsersStore } from '@/features/settings/usersStore'
import { confirmAction } from '@/composables/useConfirm'
import { initials } from '@/utils/format'

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
  Object.assign(form, {
    name: '',
    email: '',
    password: '',
    role_id: '',
    department_id: '',
    phone: '',
  })
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
  <div>
    <PageHeader title="Users" subtitle="People with access. Exactly one role each.">
      <template #actions>
        <Button label="New user" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <SectionCard flush>
      <!-- Filters -->
      <div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3 sm:px-5">
        <BaseSelect
          v-model="store.filters.role_id"
          aria-label="Filter by role"
          placeholder="All roles"
          class="w-full sm:w-44"
          :options="store.roles.map((r) => ({ value: r.id, label: r.name }))"
          @change="store.fetch()"
        />
        <BaseSelect
          v-model="store.filters.department_id"
          aria-label="Filter by department"
          placeholder="All departments"
          class="w-full sm:w-48"
          :options="store.departments.map((d) => ({ value: d.id, label: d.name }))"
          @change="store.fetch()"
        />
        <BaseSelect
          v-model="store.filters.is_active"
          aria-label="Filter by state"
          placeholder="Any state"
          class="w-full sm:w-40"
          :options="[
            { value: '1', label: 'Active' },
            { value: '0', label: 'Inactive' },
          ]"
          @change="store.fetch()"
        />
      </div>

      <EmptyState v-if="!store.items.length" icon="pi pi-users" title="No users match" />

      <ul v-else class="divide-y divide-line">
        <li
          v-for="user in store.items"
          :key="user.id"
          class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:px-5"
        >
          <div class="flex min-w-0 flex-1 items-center gap-3">
            <Avatar
              :label="initials(user.name)"
              shape="circle"
              class="shrink-0 !bg-highlight !text-primary-700 dark:!text-primary-300"
              :class="{ 'opacity-50': !user.is_active }"
            />
            <div class="min-w-0">
              <p class="flex flex-wrap items-center gap-2">
                <span class="truncate text-sm font-medium text-ink">{{ user.name }}</span>
                <Tag v-if="!user.is_active" value="inactive" severity="secondary" />
                <Tag v-if="user.role?.is_agent" value="agent" severity="info" />
              </p>
              <p class="truncate text-xs text-mute">
                {{ user.email }} · {{ user.role?.name ?? '—' }}
                <template v-if="user.department"> · {{ user.department.name }}</template>
              </p>
            </div>
          </div>
          <div class="flex items-center gap-1">
            <Button
              icon="pi pi-pencil"
              text
              rounded
              size="small"
              severity="secondary"
              aria-label="Edit user"
              @click="openEdit(user)"
            />
            <Button
              :icon="user.is_active ? 'pi pi-pause' : 'pi pi-play'"
              :label="user.is_active ? 'Deactivate' : 'Activate'"
              text
              size="small"
              severity="secondary"
              @click="toggleActive(user)"
            />
            <Button
              icon="pi pi-ban"
              text
              rounded
              size="small"
              severity="danger"
              aria-label="Cancel user"
              @click="cancelUser(user)"
            />
          </div>
        </li>
      </ul>
    </SectionCard>

    <!-- Create / edit drawer -->
    <Drawer
      v-model:visible="drawerOpen"
      position="right"
      class="!w-full sm:!w-[440px]"
      :header="editingId ? 'Edit user' : 'New user'"
    >
      <form class="space-y-4" @submit.prevent="save">
        <BaseInput v-model="form.name" label="Name" />
        <BaseInput v-model="form.email" label="Email" type="email" />
        <div>
          <BaseInput v-model="form.password" label="Password" type="password" />
          <p v-if="editingId" class="mt-1 text-xs text-mute">
            Leave blank to keep the current password.
          </p>
        </div>

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
          <Button type="submit" label="Save" icon="pi pi-check" :loading="store.saving" />
          <Button
            type="button"
            label="Cancel"
            severity="secondary"
            outlined
            @click="drawerOpen = false"
          />
        </div>
      </form>
    </Drawer>
  </div>
</template>
