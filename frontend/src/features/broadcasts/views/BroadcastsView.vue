<script setup>
import { onMounted, ref } from 'vue'
import Button from 'primevue/button'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import BroadcastComposer from '@/features/broadcasts/components/BroadcastComposer.vue'
import BroadcastDetailModal from '@/features/broadcasts/components/BroadcastDetailModal.vue'
import { useBroadcastsStore } from '@/features/broadcasts/broadcastsStore'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { formatDateTime } from '@/utils/format'
import { t } from '@/i18n'

// Broadcasts admin page: compose + send a custom notification (top), then the
// company-wide history of everything sent (bottom). Clicking a row opens its
// full detail — audience, every language and who has read it.
const store = useBroadcastsStore()
const openId = ref(null)

function audienceLabel(row) {
  if (row.audience_type === 'role')
    return t('broadcasts.toRole', { role: row.role_name ?? '', count: row.recipient_count })
  if (row.audience_type === 'users') return t('broadcasts.toUsers', { count: row.recipient_count })
  return t('broadcasts.toEveryone', { count: row.recipient_count })
}

useRefreshable(() => store.fetch())
onMounted(() => store.fetch(1))
</script>

<template>
  <div>
    <PageHeader :title="$t('nav.broadcasts')" :subtitle="$t('broadcasts.subtitle')" />

    <BroadcastComposer class="mb-5" />

    <SectionCard :title="$t('broadcasts.historyTitle')" flush>
      <p v-if="store.loading" class="py-8 text-center text-sm text-mute">{{ $t('common.loading') }}</p>
      <EmptyState
        v-else-if="!store.items.length"
        icon="pi pi-megaphone"
        :title="$t('broadcasts.historyEmpty')"
      />

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-start text-xs text-mute">
              <th class="px-4 py-2.5 font-medium sm:px-5">{{ $t('broadcasts.colWhen') }}</th>
              <th class="py-2.5 pe-3 font-medium">{{ $t('broadcasts.colFrom') }}</th>
              <th class="py-2.5 pe-3 font-medium">{{ $t('broadcasts.colAudience') }}</th>
              <th class="py-2.5 pe-3 font-medium">{{ $t('broadcasts.colMessage') }}</th>
              <th class="py-2.5 pe-4 text-end font-medium">{{ $t('broadcasts.colRead') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in store.items"
              :key="row.id"
              class="cursor-pointer border-t border-line transition-colors hover:bg-surface-50 dark:hover:bg-surface-900"
              @click="openId = row.id"
            >
              <td class="num whitespace-nowrap px-4 py-2.5 sm:px-5">{{ formatDateTime(row.created_at) }}</td>
              <td class="py-2.5 pe-3">{{ row.sender_name ?? '—' }}</td>
              <td class="py-2.5 pe-3">{{ audienceLabel(row) }}</td>
              <td class="max-w-xs truncate py-2.5 pe-3 text-mute">{{ row.preview }}</td>
              <td class="num whitespace-nowrap py-2.5 pe-4 text-end text-mute">
                {{ row.read_count }} / {{ row.recipient_count }}
              </td>
            </tr>
          </tbody>
        </table>

        <div
          v-if="store.meta.last_page > 1"
          class="flex items-center justify-between border-t border-line px-4 py-3 text-sm sm:px-5"
        >
          <Button
            :label="$t('common.back')"
            icon="pi pi-chevron-left"
            text
            size="small"
            :disabled="store.page <= 1"
            @click="store.goToPage(store.page - 1)"
          />
          <span class="num text-mute">Page {{ store.meta.current_page }} / {{ store.meta.last_page }}</span>
          <Button
            :label="$t('common.next')"
            icon="pi pi-chevron-right"
            icon-pos="right"
            text
            size="small"
            :disabled="store.page >= store.meta.last_page"
            @click="store.goToPage(store.page + 1)"
          />
        </div>
      </div>
    </SectionCard>

    <BroadcastDetailModal v-if="openId" :id="openId" @close="openId = null" />
  </div>
</template>
