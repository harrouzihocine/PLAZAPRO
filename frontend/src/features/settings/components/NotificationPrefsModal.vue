<script setup>
import { reactive, ref } from 'vue'
import Button from 'primevue/button'
import ToggleSwitch from 'primevue/toggleswitch'
import BaseModal from '@/components/base/BaseModal.vue'
import { useAuthStore } from '@/features/settings/store'
import { toastError, toastSuccess } from '@/composables/useConfirm'
import { t } from '@/i18n'

// Which notification categories reach the phone as a system-tray push. The
// matrix mirrors DomainNotification::PUSH_CATEGORIES on the backend — push
// only: everything always stays in the in-app bell, and security notices
// (e.g. account locked) can never be silenced.
const emit = defineEmits(['close'])
const auth = useAuthStore()

const CATEGORIES = [
  {
    key: 'chat',
    icon: 'pi pi-comments',
    labelKey: 'pushPrefs.chat',
    hintKey: 'pushPrefs.chatHint',
  },
  {
    key: 'visits',
    icon: 'pi pi-send',
    labelKey: 'pushPrefs.visits',
    hintKey: 'pushPrefs.visitsHint',
  },
  {
    key: 'payments',
    icon: 'pi pi-wallet',
    labelKey: 'pushPrefs.payments',
    hintKey: 'pushPrefs.paymentsHint',
  },
  {
    key: 'reminders',
    icon: 'pi pi-clock',
    labelKey: 'pushPrefs.reminders',
    hintKey: 'pushPrefs.remindersHint',
  },
  {
    key: 'listings',
    icon: 'pi pi-th-large',
    labelKey: 'pushPrefs.listings',
    hintKey: 'pushPrefs.listingsHint',
  },
  {
    key: 'workflow',
    icon: 'pi pi-users',
    labelKey: 'pushPrefs.workflow',
    hintKey: 'pushPrefs.workflowHint',
  },
]

// Missing key = never customised = on.
const prefs = reactive(
  Object.fromEntries(CATEGORIES.map((c) => [c.key, auth.user?.push_prefs?.[c.key] ?? true])),
)

const saving = ref(false)

async function save() {
  if (saving.value) return
  saving.value = true
  try {
    await auth.updatePushPrefs({ ...prefs })
    toastSuccess(t('pushPrefs.saved'))
    emit('close')
  } catch (e) {
    toastError(e.response?.data?.message ?? t('pushPrefs.saveFailed'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :title="$t('pushPrefs.title')" size="max-w-lg" @close="emit('close')">
    <form class="space-y-5" @submit.prevent="save">
      <p class="text-sm text-mute">
        Choose what reaches your phone as a push notification. Everything still
        appears in the bell inside the app.
      </p>

      <div class="divide-y divide-line rounded-lg border border-line">
        <label
          v-for="c in CATEGORIES"
          :key="c.key"
          class="flex cursor-pointer items-center gap-3 px-4 py-3"
        >
          <i :class="c.icon" class="w-5 text-center text-mute" aria-hidden="true" />
          <span class="min-w-0 flex-1">
            <span class="block text-sm font-medium text-ink">{{ $t(c.labelKey) }}</span>
            <span class="block text-xs text-mute">{{ $t(c.hintKey) }}</span>
          </span>
          <ToggleSwitch v-model="prefs[c.key]" />
        </label>
      </div>

      <p class="text-xs text-mute">
        Security alerts (like account locks) are always delivered.
      </p>

      <div class="flex justify-end gap-2 pt-1">
        <Button type="button" :label="$t('common.cancel')" severity="secondary" outlined @click="emit('close')" />
        <Button type="submit" :label="$t('common.save')" icon="pi pi-check" :loading="saving" />
      </div>
    </form>
  </BaseModal>
</template>
