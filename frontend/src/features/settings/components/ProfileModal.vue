<script setup>
import { computed, reactive, ref } from 'vue'
import Avatar from 'primevue/avatar'
import Button from 'primevue/button'
import BaseModal from '@/components/base/BaseModal.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import BasePhoneInput from '@/components/base/BasePhoneInput.vue'
import ImageCropperModal from '@/features/settings/components/ImageCropperModal.vue'
import { useAuthStore } from '@/features/settings/store'
import { toastError, toastSuccess } from '@/composables/useConfirm'
import { initials } from '@/utils/format'
import { t } from '@/i18n'

// The signed-in user editing their OWN account: details, password and photo.
// The username is fixed here — only an admin can change it (Settings → Users).
const emit = defineEmits(['close'])
const auth = useAuthStore()

const form = reactive({
  name: auth.user?.name ?? '',
  email: auth.user?.email ?? '',
  phone: auth.user?.phone ?? '',
  current_password: '',
  password: '',
  password_confirmation: '',
})

const saving = ref(false)
const avatarBusy = ref(false)
const fileInput = ref(null)
const cropFile = ref(null) // the picked file, shown in the cropper before upload

const wantsPasswordChange = computed(() => Boolean(form.password || form.password_confirmation))
const passwordMismatch = computed(
  () => wantsPasswordChange.value && form.password !== form.password_confirmation,
)
const valid = computed(
  () =>
    form.name.trim() &&
    form.email.trim() &&
    !passwordMismatch.value &&
    (!wantsPasswordChange.value || form.current_password),
)

async function save() {
  if (!valid.value || saving.value) return
  const payload = {
    name: form.name.trim(),
    email: form.email.trim(),
    phone: form.phone.trim() || null,
  }
  if (wantsPasswordChange.value) {
    payload.password = form.password
    payload.password_confirmation = form.password_confirmation
    payload.current_password = form.current_password
  }

  saving.value = true
  try {
    await auth.updateProfile(payload)
    toastSuccess(t('profile.updated'))
    emit('close')
  } catch (e) {
    toastError(e.response?.data?.message ?? t('profile.saveFailed'))
  } finally {
    saving.value = false
  }
}

function pickPhoto() {
  fileInput.value?.click()
}

function onFile(event) {
  const file = event.target.files?.[0]
  event.target.value = '' // allow re-picking the same file
  if (!file) return

  if (!file.type.startsWith('image/')) {
    toastError(t('profile.chooseImage'))
    return
  }

  // Hand off to the cropper; the actual upload happens on "Apply".
  cropFile.value = file
}

async function onCropped(croppedFile) {
  cropFile.value = null
  avatarBusy.value = true
  try {
    await auth.uploadAvatar(croppedFile)
    toastSuccess(t('profile.photoUpdated'))
  } catch (e) {
    toastError(e.response?.data?.message ?? t('profile.photoUploadFailed'))
  } finally {
    avatarBusy.value = false
  }
}

async function removePhoto() {
  avatarBusy.value = true
  try {
    await auth.removeAvatar()
    toastSuccess(t('profile.photoRemoved'))
  } catch (e) {
    toastError(e.response?.data?.message ?? t('profile.photoRemoveFailed'))
  } finally {
    avatarBusy.value = false
  }
}
</script>

<template>
  <BaseModal :title="$t('shell.editProfile')" size="max-w-lg" @close="emit('close')">
    <form class="space-y-5" @submit.prevent="save">
      <!-- Photo -->
      <div class="flex items-center gap-4">
        <Avatar
          :image="auth.user?.avatar_url || undefined"
          :label="auth.user?.avatar_url ? undefined : initials(auth.user?.name)"
          size="xlarge"
          shape="circle"
          class="!h-20 !w-20 !bg-primary !text-primary-contrast"
        />
        <div class="flex flex-col gap-2">
          <div class="flex gap-2">
            <Button
              type="button"
:label="$t('profile.changePhoto')"
              icon="pi pi-camera"
              size="small"
              outlined
              :loading="avatarBusy"
              @click="pickPhoto"
            />
            <Button
              v-if="auth.user?.avatar_url"
              type="button"
:label="$t('common.remove')"
              icon="pi pi-trash"
              severity="danger"
              text
              size="small"
              :disabled="avatarBusy"
              @click="removePhoto"
            />
          </div>
          <p class="text-xs text-mute">JPG or PNG. Resized and compressed automatically.</p>
          <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onFile" />
        </div>
      </div>

      <!-- Username (read-only — admin-managed) -->
      <div>
        <label class="mb-1.5 block text-sm font-medium text-ink">Username</label>
        <div
          class="flex items-center rounded-md border border-line bg-ground px-3 py-2 text-sm text-mute"
        >
          {{ auth.user?.username }}
        </div>
        <p class="mt-1 text-xs text-mute">
          Contact an administrator to change your username.
        </p>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <BaseInput v-model="form.name" :label="$t('common.name')" capitalize required />
        <BaseInput v-model="form.email" :label="$t('common.email')" type="email" required />
      </div>

      <BasePhoneInput v-model="form.phone" :label="$t('common.phone')" />

      <!-- Password change (optional) -->
      <div class="space-y-4 rounded-lg border border-line p-4">
        <p class="text-sm font-medium text-ink">{{ $t('profile.changePassword') }}</p>
        <p class="-mt-2 text-xs text-mute">{{ $t('profile.blankKeep') }}</p>
        <BaseInput
          v-model="form.current_password"
:label="$t('profile.currentPassword')"
          type="password"
          :required="wantsPasswordChange"
        />
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.password" :label="$t('profile.newPassword')" type="password" />
          <BaseInput
            v-model="form.password_confirmation"
:label="$t('profile.confirmPassword')"
            type="password"
            :error="passwordMismatch ? $t('profile.passwordsMismatch') : ''"
          />
        </div>
      </div>

      <div class="flex justify-end gap-2 pt-1">
        <Button type="button" :label="$t('common.cancel')" severity="secondary" outlined @click="emit('close')" />
        <Button
          type="submit"
:label="$t('chat.saveChanges')"
          icon="pi pi-check"
          :loading="saving"
          :disabled="!valid"
        />
      </div>
    </form>

    <ImageCropperModal
      v-if="cropFile"
      :file="cropFile"
      @confirm="onCropped"
      @cancel="cropFile = null"
    />
  </BaseModal>
</template>
