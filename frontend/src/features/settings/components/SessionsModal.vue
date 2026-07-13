<script setup>
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import BaseModal from '@/components/base/BaseModal.vue'
import { authApi } from '@/features/settings/api'
import { confirmAction, toastError, toastSuccess } from '@/composables/useConfirm'
import { timeAgo } from '@/utils/format'
import { t } from '@/i18n'

// Connected devices: every session this account is signed in on (the current
// one first), with the power to force-end any of them — one at a time, or
// everything except the device in hand. Ending a session also voids its
// remember-me cookie server-side, so the device is truly out.
const emit = defineEmits(['close'])

const sessions = ref([])
const loading = ref(true)
const busy = ref(false)

async function load() {
  try {
    sessions.value = await authApi.sessions()
  } catch {
    toastError(t('sessions.loadFailed'))
  } finally {
    loading.value = false
  }
}
onMounted(load)

const others = computed(() => sessions.value.filter((s) => !s.is_current))

const ICONS = { phone: 'pi pi-mobile', tablet: 'pi pi-tablet' }

function deviceTitle(s) {
  const browser = s.is_app ? t('sessions.plazaApp') : s.browser || t('sessions.unknownDevice')
  return s.platform ? `${browser} · ${s.platform}` : browser
}

async function endSession(s) {
  if (busy.value) return
  const ok = await confirmAction({
    title: t('sessions.endTitle'),
    text: t('sessions.endText', { device: deviceTitle(s) }),
    confirmText: t('sessions.end'),
    danger: true,
  })
  if (!ok) return

  busy.value = true
  try {
    await authApi.endSession(s.id)
    toastSuccess(t('sessions.ended'))
  } catch (e) {
    toastError(e.response?.data?.message ?? t('sessions.endFailed'))
  } finally {
    busy.value = false
    await load() // refetch either way — a 404 means it was already gone
  }
}

async function endOthers() {
  if (busy.value) return
  const ok = await confirmAction({
    title: t('sessions.endOthersTitle'),
    text: t('sessions.endOthersText'),
    confirmText: t('sessions.endOthers'),
    danger: true,
  })
  if (!ok) return

  busy.value = true
  try {
    await authApi.endOtherSessions()
    toastSuccess(t('sessions.endedOthers'))
  } catch (e) {
    toastError(e.response?.data?.message ?? t('sessions.endFailed'))
  } finally {
    busy.value = false
    await load()
  }
}
</script>

<template>
  <BaseModal :title="$t('sessions.title')" size="max-w-lg" @close="emit('close')">
    <div class="space-y-5">
      <p class="text-sm text-mute">{{ $t('sessions.intro') }}</p>

      <div v-if="loading" class="flex justify-center py-8">
        <i class="pi pi-spinner pi-spin text-2xl text-mute" aria-hidden="true" />
      </div>

      <div v-else class="divide-y divide-line rounded-lg border border-line">
        <div v-for="s in sessions" :key="s.id" class="flex items-center gap-3 px-4 py-3">
          <i
            :class="ICONS[s.device] ?? 'pi pi-desktop'"
            class="w-5 text-center text-lg text-mute"
            aria-hidden="true"
          />
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-ink">{{ deviceTitle(s) }}</p>
            <p class="truncate text-xs text-mute" dir="ltr">
              {{ s.ip_address }}
              <template v-if="!s.is_current">
                · {{ $t('sessions.lastActive', { when: timeAgo(s.last_active_at) }) }}
              </template>
            </p>
          </div>
          <Tag v-if="s.is_current" :value="$t('sessions.thisDevice')" severity="success" />
          <Button
            v-else
            :label="$t('sessions.end')"
            severity="danger"
            text
            size="small"
            :disabled="busy"
            @click="endSession(s)"
          />
        </div>
      </div>

      <div class="flex flex-wrap justify-end gap-2 pt-1">
        <Button
          type="button"
          :label="$t('common.close')"
          severity="secondary"
          outlined
          @click="emit('close')"
        />
        <Button
          v-if="others.length"
          type="button"
          :label="$t('sessions.endOthers')"
          icon="pi pi-sign-out"
          severity="danger"
          outlined
          :disabled="busy"
          @click="endOthers"
        />
      </div>
    </div>
  </BaseModal>
</template>
