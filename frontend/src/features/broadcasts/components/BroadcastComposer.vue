<script setup>
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import BaseSelect from '@/components/base/BaseSelect.vue'
import BaseMultiSelect from '@/components/base/BaseMultiSelect.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import { useBroadcastsStore } from '@/features/broadcasts/broadcastsStore'
import { staffApi } from '@/features/clients/api'
import { rolesApi } from '@/features/settings/api'
import { confirmAction, toastSuccess } from '@/composables/useConfirm'
import { t } from '@/i18n'

// Compose + send a custom broadcast notification. Audience is everyone, a whole
// role, or a hand-picked list of users. The message is written in any of the
// three languages; each recipient reads it in their own (the backend renders
// per recipient), so the sender just fills whichever languages they can.
const store = useBroadcastsStore()

const AUDIENCE = { ALL: 'all', ROLE: 'role', USERS: 'users' }
const audienceType = ref(AUDIENCE.ALL)
const roleId = ref('')
const userIds = ref([])
const body = ref({ en: '', fr: '', ar: '' })

const staff = ref([])
const roles = ref([])

const audienceOptions = computed(() => [
  { value: AUDIENCE.ALL, label: t('broadcasts.audienceEveryone') },
  { value: AUDIENCE.ROLE, label: t('broadcasts.audienceRole') },
  { value: AUDIENCE.USERS, label: t('broadcasts.audienceUsers') },
])
const roleOptions = computed(() =>
  roles.value.map((r) => ({ value: r.id, label: `${r.name} (${r.users_count ?? 0})` })),
)
const userOptions = computed(() => staff.value.map((u) => ({ value: u.id, label: u.name })))

const hasMessage = computed(() => Object.values(body.value).some((v) => v.trim() !== ''))
const audienceReady = computed(() => {
  if (audienceType.value === AUDIENCE.ROLE) return Boolean(roleId.value)
  if (audienceType.value === AUDIENCE.USERS) return userIds.value.length > 0
  return true
})
const canSend = computed(() => hasMessage.value && audienceReady.value && !store.sending)

onMounted(async () => {
  try {
    ;[staff.value, roles.value] = await Promise.all([staffApi.list(), rolesApi.list()])
  } catch {
    /* the pickers are best-effort; a failed load leaves them empty */
  }
})

function confirmText() {
  if (audienceType.value === AUDIENCE.USERS) {
    return t('broadcasts.confirmUsers', { count: userIds.value.length })
  }
  if (audienceType.value === AUDIENCE.ROLE) {
    const role = roles.value.find((r) => r.id === roleId.value)
    return t('broadcasts.confirmRole', { role: role?.name ?? '' })
  }
  return t('broadcasts.confirmEveryone')
}

async function submit() {
  if (!canSend.value) return
  const ok = await confirmAction({
    title: t('broadcasts.confirmTitle'),
    text: confirmText(),
    confirmText: t('broadcasts.send'),
  })
  if (!ok) return

  const payload = {
    audience_type: audienceType.value,
    body: Object.fromEntries(
      Object.entries(body.value).map(([k, v]) => [k, v.trim()]).filter(([, v]) => v !== ''),
    ),
  }
  if (audienceType.value === AUDIENCE.ROLE) payload.role_id = roleId.value
  if (audienceType.value === AUDIENCE.USERS) payload.user_ids = userIds.value

  await store.send(payload)
  // Clear the draft for the next message; keep the chosen audience type.
  body.value = { en: '', fr: '', ar: '' }
  roleId.value = ''
  userIds.value = []
  toastSuccess(t('broadcasts.sent'))
}
</script>

<template>
  <SectionCard :title="$t('broadcasts.composeTitle')">
    <form class="space-y-4" @submit.prevent="submit">
      <div class="grid gap-3 sm:grid-cols-2">
        <BaseSelect
          v-model="audienceType"
          :label="$t('broadcasts.sendTo')"
          :options="audienceOptions"
          :searchable="false"
          required
        />
        <BaseSelect
          v-if="audienceType === AUDIENCE.ROLE"
          v-model="roleId"
          :label="$t('broadcasts.role')"
          :options="roleOptions"
          :placeholder="$t('broadcasts.pickRole')"
          required
        />
        <BaseMultiSelect
          v-else-if="audienceType === AUDIENCE.USERS"
          v-model="userIds"
          :label="$t('broadcasts.users')"
          :options="userOptions"
          :placeholder="$t('broadcasts.pickUsers')"
          required
        />
      </div>

      <!-- Trilingual message: each recipient reads their own language, falling
           back to any filled one. At least one language is required. -->
      <fieldset class="space-y-3 rounded-xl border border-line p-3">
        <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-mute">
          {{ $t('broadcasts.message') }}
        </legend>
        <p class="text-xs text-mute">{{ $t('broadcasts.messageHint') }}</p>
        <BaseTextarea v-model="body.en" label="English" dir="ltr" :rows="2" />
        <BaseTextarea v-model="body.fr" label="Français" dir="ltr" :rows="2" />
        <BaseTextarea v-model="body.ar" label="العربية" dir="rtl" :rows="2" />
      </fieldset>

      <div class="flex justify-end">
        <Button
          type="submit"
          :label="$t('broadcasts.send')"
          icon="pi pi-send"
          :loading="store.sending"
          :disabled="!canSend"
        />
      </div>
    </form>
  </SectionCard>
</template>
