<script setup>
import { onMounted, ref } from 'vue'
import Button from 'primevue/button'
import BaseInput from '@/components/base/BaseInput.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import { toastError, toastSuccess } from '@/composables/useConfirm'
import { appSettingsApi } from '@/features/settings/api'

// Scalar app-wide settings. Reservation hold = how long a plain reservation
// lasts (a hold backing an open deal never expires). On-hold window = how long a
// unit stays off the market after a holding deposit before the sweep frees it.
const holdHours = ref('')
const onholdHours = ref('')
const loading = ref(true)
const saving = ref(false)

onMounted(async () => {
  try {
    const settings = await appSettingsApi.get()
    holdHours.value = settings.reservation_hold_hours ?? '48'
    onholdHours.value = settings.onhold_hold_hours ?? '72'
  } catch {
    toastError('Could not load the app settings.')
  } finally {
    loading.value = false
  }
})

async function save() {
  const hours = Number(holdHours.value)
  const onhold = Number(onholdHours.value)
  if (!Number.isInteger(hours) || hours < 1 || !Number.isInteger(onhold) || onhold < 1) {
    toastError('Enter whole numbers of hours (at least 1).')
    return
  }
  saving.value = true
  try {
    await appSettingsApi.save({ reservation_hold_hours: hours, onhold_hold_hours: onhold })
    toastSuccess('Settings saved.')
  } catch (e) {
    toastError(e.response?.data?.message ?? 'Could not save the settings.')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader title="General" subtitle="Workspace-wide options." />
    <SectionCard title="Reservations" icon="pi pi-clock">
      <p v-if="loading" class="text-sm text-mute">Loading…</p>
      <form v-else class="max-w-md space-y-4" @submit.prevent="save">
        <div>
          <BaseInput
            v-model="holdHours"
            label="Reservation hold duration (hours)"
            type="number"
            min="1"
          />
          <p class="mt-1.5 text-xs text-mute">
            How long a unit stays held after a plain reservation before the sweep releases it back
            to available. A hold backing an open deal never expires — only closing the deal (won /
            lost) releases or converts it.
          </p>
        </div>
        <div>
          <BaseInput
            v-model="onholdHours"
            label="On-hold window after a holding deposit (hours)"
            type="number"
            min="1"
          />
          <p class="mt-1.5 text-xs text-mute">
            When a client pays a holding deposit, the unit goes On Hold — off the market for
            everyone else (backups aside). If it is not sold within this window, the sweep returns
            it to the market (reserved if others still hold it, else available) and notifies the
            holder.
          </p>
        </div>
        <Button type="submit" label="Save" icon="pi pi-check" :loading="saving" />
      </form>
    </SectionCard>
  </div>
</template>
