<script setup>
import { onMounted, ref } from 'vue'
import Button from 'primevue/button'
import BaseInput from '@/components/base/BaseInput.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import { toastError, toastSuccess } from '@/composables/useConfirm'
import { appSettingsApi } from '@/features/settings/api'

// Scalar app-wide settings. Interest hold = how long a plain interest hold
// lasts (a hold backing an open deal never expires). Reserved window = how long
// a unit stays off the market after a holding deposit before the sweep frees it.
const holdHours = ref('')
const reservedHours = ref('')
const loading = ref(true)
const saving = ref(false)

onMounted(async () => {
  try {
    const settings = await appSettingsApi.get()
    holdHours.value = settings.interest_hold_hours ?? '48'
    reservedHours.value = settings.reserved_hold_hours ?? '72'
  } catch {
    toastError('Could not load the app settings.')
  } finally {
    loading.value = false
  }
})

async function save() {
  const hours = Number(holdHours.value)
  const reserved = Number(reservedHours.value)
  if (!Number.isInteger(hours) || hours < 1 || !Number.isInteger(reserved) || reserved < 1) {
    toastError('Enter whole numbers of hours (at least 1).')
    return
  }
  saving.value = true
  try {
    await appSettingsApi.save({ interest_hold_hours: hours, reserved_hold_hours: reserved })
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
    <SectionCard title="Holds" icon="pi pi-clock">
      <p v-if="loading" class="text-sm text-mute">Loading…</p>
      <form v-else class="max-w-md space-y-4" @submit.prevent="save">
        <div>
          <BaseInput
            v-model="holdHours"
            label="Interest hold duration (hours)"
            type="number"
            min="1"
          />
          <p class="mt-1.5 text-xs text-mute">
            How long a unit stays Interested after a plain interest hold before the sweep releases
            it back to available. A hold backing an open deal never expires — only closing the deal
            (won / lost) releases or converts it.
          </p>
        </div>
        <div>
          <BaseInput
            v-model="reservedHours"
            label="Reserved window after a holding deposit (hours)"
            type="number"
            min="1"
          />
          <p class="mt-1.5 text-xs text-mute">
            When a client pays a holding deposit, the unit goes Reserved — off the market for
            everyone else (backups aside). If it is not sold within this window, the sweep returns
            it to the market (interested if others still hold it, else available) and notifies the
            holder.
          </p>
        </div>
        <Button type="submit" label="Save" icon="pi pi-check" :loading="saving" />
      </form>
    </SectionCard>
  </div>
</template>
