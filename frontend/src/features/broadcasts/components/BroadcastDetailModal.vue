<script setup>
import { computed, onMounted, ref } from 'vue'
import BaseModal from '@/components/base/BaseModal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { broadcastsApi } from '@/features/broadcasts/api'
import { formatDateTime, timeAgo } from '@/utils/format'
import { t } from '@/i18n'

// One broadcast's full detail, shared by the history list (broadcasters see the
// audience, every language and who has read it) and the bell (a recipient sees
// the message, who sent it and when). The backend decides which fields it
// returns per viewer — the who-read list is present only for broadcasters.
const props = defineProps({
  id: { type: [Number, String], required: true },
})
defineEmits(['close'])

const LANGS = [
  { key: 'en', label: 'English', dir: 'ltr' },
  { key: 'fr', label: 'Français', dir: 'ltr' },
  { key: 'ar', label: 'العربية', dir: 'rtl' },
]

const data = ref(null)
const loading = ref(true)
const failed = ref(false)

// Broadcaster view carries the who-read list; a plain recipient view does not.
const isManager = computed(() => Array.isArray(data.value?.recipients))
const languages = computed(() =>
  LANGS.filter((l) => (data.value?.body_translations?.[l.key] ?? '').trim() !== ''),
)
const audienceLabel = computed(() => {
  const d = data.value
  if (!d) return ''
  if (d.audience_type === 'role')
    return t('broadcasts.toRole', { role: d.role_name ?? '', count: d.recipient_count })
  if (d.audience_type === 'users') return t('broadcasts.toUsers', { count: d.recipient_count })
  return t('broadcasts.toEveryone', { count: d.recipient_count })
})

onMounted(async () => {
  try {
    data.value = await broadcastsApi.show(props.id)
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <BaseModal :title="$t('broadcasts.detailTitle')" size="max-w-xl" @close="$emit('close')">
    <p v-if="loading" class="py-8 text-center text-sm text-mute">{{ $t('common.loading') }}</p>
    <EmptyState v-else-if="failed" icon="pi pi-exclamation-triangle" :title="$t('common.actionFailed')" />

    <div v-else-if="data" class="space-y-4">
      <!-- Who sent it, when, and (broadcasters) to whom. -->
      <div class="text-sm text-mute">
        <span v-if="data.sender_name" class="font-medium text-ink">{{ data.sender_name }}</span>
        <span v-if="data.sender_name"> · </span>{{ formatDateTime(data.created_at) }}
        <span v-if="isManager" class="mt-0.5 block">{{ audienceLabel }}</span>
      </div>

      <!-- The message. Broadcasters see every language they wrote; a recipient
           sees it in their own language. -->
      <div v-if="isManager && languages.length" class="space-y-3">
        <div v-for="lang in languages" :key="lang.key" class="rounded-lg border border-line p-3">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-mute">{{ lang.label }}</p>
          <p class="whitespace-pre-wrap text-sm text-ink" :dir="lang.dir">
            {{ data.body_translations[lang.key] }}
          </p>
        </div>
      </div>
      <p v-else class="whitespace-pre-wrap text-sm text-ink">{{ data.body }}</p>

      <!-- Read tracking (broadcasters only): a delivered/read tally + who read it. -->
      <div v-if="isManager" class="rounded-xl border border-line">
        <div class="flex items-center justify-between border-b border-line px-4 py-2.5 text-sm">
          <span class="font-medium text-ink">{{ $t('broadcasts.readTracking') }}</span>
          <span class="num text-mute">
            {{ $t('broadcasts.readOfDelivered', { read: data.read_count, total: data.delivered }) }}
          </span>
        </div>
        <ul class="max-h-64 overflow-y-auto divide-y divide-line">
          <li
            v-for="r in data.recipients"
            :key="r.id"
            class="flex items-center justify-between px-4 py-2 text-sm"
          >
            <span class="text-ink">{{ r.name }}</span>
            <span v-if="r.read_at" class="inline-flex items-center gap-1 text-xs text-mute">
              <i class="pi pi-check-circle text-primary" aria-hidden="true" />
              {{ timeAgo(r.read_at) }}
            </span>
            <span v-else class="text-xs text-mute">{{ $t('broadcasts.notReadYet') }}</span>
          </li>
        </ul>
      </div>
    </div>
  </BaseModal>
</template>
