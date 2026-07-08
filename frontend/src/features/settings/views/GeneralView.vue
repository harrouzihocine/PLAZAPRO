<script setup>
import { onMounted, ref } from 'vue'
import Button from 'primevue/button'
import BaseInput from '@/components/base/BaseInput.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import { toastError, toastSuccess } from '@/composables/useConfirm'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { appSettingsApi } from '@/features/settings/api'
import { t } from '@/i18n'

// Scalar app-wide settings. Interest hold = how long a plain interest hold
// lasts (a hold backing an open deal never expires). Reserved window = how long
// a unit stays off the market after a holding deposit before the sweep frees it.
// Login security = failed password attempts before an account locks, and how
// long the lock lasts (0 = until an admin unlocks it in Users).
const holdHours = ref('')
const reservedHours = ref('')
const maxAttempts = ref('')
const lockoutMinutes = ref('')
const loading = ref(true)
const saving = ref(false)

async function load() {
  try {
    const settings = await appSettingsApi.get()
    holdHours.value = settings.interest_hold_hours ?? '48'
    reservedHours.value = settings.reserved_hold_hours ?? '72'
    maxAttempts.value = settings.login_max_attempts ?? '3'
    lockoutMinutes.value = settings.login_lockout_minutes ?? '0'
  } catch {
    toastError(t('settings.loadFailed'))
  } finally {
    loading.value = false
  }
}

onMounted(load)
useRefreshable(load) // pull-to-refresh + reconnect self-heal

async function save() {
  const hours = Number(holdHours.value)
  const reserved = Number(reservedHours.value)
  const attempts = Number(maxAttempts.value)
  const lockout = Number(lockoutMinutes.value)
  if (!Number.isInteger(hours) || hours < 1 || !Number.isInteger(reserved) || reserved < 1) {
    toastError(t('settings.hoursInvalid'))
    return
  }
  if (!Number.isInteger(attempts) || attempts < 1 || attempts > 10) {
    toastError(t('settings.attemptsInvalid'))
    return
  }
  if (!Number.isInteger(lockout) || lockout < 0) {
    toastError(t('settings.lockoutInvalid'))
    return
  }
  saving.value = true
  try {
    await appSettingsApi.save({
      interest_hold_hours: hours,
      reserved_hold_hours: reserved,
      login_max_attempts: attempts,
      login_lockout_minutes: lockout,
    })
    toastSuccess(t('settings.saved'))
  } catch (e) {
    toastError(e.response?.data?.message ?? t('settings.saveFailed'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="$t('settings.general')" :subtitle="$t('settings.generalSubtitle')" />
    <SectionCard :title="$t('settings.holds')" icon="pi pi-clock">
      <p v-if="loading" class="text-sm text-mute">{{ $t('common.loading') }}</p>
      <form v-else class="max-w-md space-y-4" @submit.prevent="save">
        <div>
          <BaseInput
            v-model="holdHours"
:label="$t('settings.holdHoursLabel')"
            type="number"
            min="1"
          />
          <p class="mt-1.5 text-xs text-mute">
            {{ $t('settings.holdHoursHint') }}
          </p>
        </div>
        <div>
          <BaseInput
            v-model="reservedHours"
:label="$t('settings.reservedHoursLabel')"
            type="number"
            min="1"
          />
          <p class="mt-1.5 text-xs text-mute">
            {{ $t('settings.reservedHoursHint') }}
          </p>
        </div>
        <Button type="submit" :label="$t('common.save')" icon="pi pi-check" :loading="saving" />
      </form>
    </SectionCard>

    <SectionCard :title="$t('settings.loginSecurity')" icon="pi pi-shield" class="mt-6">
      <p v-if="loading" class="text-sm text-mute">{{ $t('common.loading') }}</p>
      <form v-else class="max-w-md space-y-4" @submit.prevent="save">
        <div>
          <BaseInput
            v-model="maxAttempts"
:label="$t('settings.maxAttemptsLabel')"
            type="number"
            min="1"
            max="10"
          />
          <p class="mt-1.5 text-xs text-mute">
            {{ $t('settings.maxAttemptsHint') }}
          </p>
        </div>
        <div>
          <BaseInput
            v-model="lockoutMinutes"
:label="$t('settings.lockoutLabel')"
            type="number"
            min="0"
          />
          <p class="mt-1.5 text-xs text-mute">
            {{ $t('settings.lockoutHint') }}
          </p>
        </div>
        <Button type="submit" :label="$t('common.save')" icon="pi pi-check" :loading="saving" />
      </form>
    </SectionCard>
  </div>
</template>
