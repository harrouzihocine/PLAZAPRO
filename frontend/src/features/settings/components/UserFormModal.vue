<script setup>
import { computed, reactive } from 'vue'
import Button from 'primevue/button'
import BaseModal from '@/components/base/BaseModal.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import BasePhoneInput from '@/components/base/BasePhoneInput.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'

// Add / edit a staff account. Exactly one role is required; department and phone
// are optional. On edit, leaving the password blank keeps the current one.
const props = defineProps({
  user: { type: Object, default: null }, // null = creating
  roles: { type: Array, default: () => [] },
  departments: { type: Array, default: () => [] },
  saving: { type: Boolean, default: false },
})
const emit = defineEmits(['save', 'close'])

const isEdit = computed(() => Boolean(props.user))
const form = reactive({
  name: props.user?.name ?? '',
  email: props.user?.email ?? '',
  username: props.user?.username ?? '',
  password: '',
  role_id: props.user?.role?.id ?? '',
  department_id: props.user?.department?.id ?? '',
  phone: props.user?.phone ?? '',
})

const valid = computed(
  () => form.name.trim() && form.email.trim() && form.username.trim() && form.role_id,
)

function submit() {
  if (!valid.value) return
  const payload = {
    name: form.name.trim(),
    email: form.email.trim(),
    // Lowercased handle; the API enforces the [a-z0-9._] format too.
    username: form.username.trim().toLowerCase(),
    role_id: form.role_id,
    department_id: form.department_id || null,
    phone: form.phone.trim() || null,
  }
  // Password only travels when set (required on create, optional on edit).
  if (form.password) payload.password = form.password
  emit('save', payload)
}
</script>

<template>
  <BaseModal :title="isEdit ? 'Edit user' : 'New user'" size="max-w-lg" @close="emit('close')">
    <form class="space-y-4" @submit.prevent="submit">
      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.name" label="Name" required capitalize />
        <BaseInput v-model="form.email" label="Email" type="email" required />
      </div>

      <div>
        <BaseInput v-model="form.username" label="Username" required placeholder="e.g. sami.k" />
        <p class="mt-1 text-xs text-mute">
          Used to sign in. Letters, digits, dot and underscore only. Users can't change their own.
        </p>
      </div>

      <div>
        <BaseInput v-model="form.password" label="Password" type="password" :required="!isEdit" />
        <p v-if="isEdit" class="mt-1 text-xs text-mute">Leave blank to keep the current password.</p>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <BaseSelect
          v-model="form.role_id"
          label="Role"
          required
          placeholder="Select a role"
          :clearable="false"
          :options="roles.map((r) => ({ value: r.id, label: r.name }))"
        />
        <BaseSelect
          v-model="form.department_id"
          label="Department"
          placeholder="None"
          :options="departments.map((d) => ({ value: d.id, label: d.name }))"
        />
      </div>

      <BasePhoneInput v-model="form.phone" label="Phone" />

      <div class="flex justify-end gap-2 pt-2">
        <Button type="button" label="Cancel" severity="secondary" outlined @click="emit('close')" />
        <Button
          type="submit"
          :label="isEdit ? 'Save changes' : 'Create user'"
          icon="pi pi-check"
          :loading="saving"
          :disabled="!valid"
        />
      </div>
    </form>
  </BaseModal>
</template>
