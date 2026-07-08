<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import OverlayBadge from 'primevue/overlaybadge'
import Popover from 'primevue/popover'
import { useOutboxStore } from '@/features/offline/outboxStore'
import { useNetworkStore } from '@/features/offline/networkStore'
import { confirmAction } from '@/composables/useConfirm'
import { timeAgo } from '@/utils/format'
import { t } from '@/i18n'

// The Sync Center: offline changes waiting to sync, and the ones the server
// rejected on reconnect — each with the server's own reason ("This visit is
// already completed.", "This project is frozen…"). Sits next to the drafts
// indicator; hidden while the queue is empty.
const outbox = useOutboxStore()
const network = useNetworkStore()
const router = useRouter()
const panel = ref(null)

function statusOf(item) {
  if (item.status === 'failed') return { icon: 'pi pi-exclamation-circle', cls: 'text-danger' }
  if (item.status === 'syncing') return { icon: 'pi pi-spinner pi-spin', cls: 'text-primary-600' }
  return { icon: 'pi pi-clock', cls: 'text-amber-500' }
}

function view(item) {
  if (!item.entityHint?.route) return
  panel.value?.hide()
  router.push(item.entityHint.route)
}

async function discard(item) {
  if (
    await confirmAction({
      title: t('sync.discardTitle'),
      text: t('sync.discardText', { label: item.label }),
      confirmText: t('sync.discard'),
      danger: true,
    })
  ) {
    outbox.discard(item.uuid)
  }
}
</script>

<template>
  <div v-if="outbox.badge > 0">
    <OverlayBadge
      :value="String(outbox.badge)"
      :severity="outbox.failed.length ? 'danger' : 'warn'"
      size="small"
    >
      <Button
        :icon="outbox.syncing ? 'pi pi-sync pi-spin' : 'pi pi-cloud-upload'"
        text
        rounded
        severity="secondary"
        :aria-label="$t('sync.offlineChangesAria', { n: outbox.badge })"
        @click="panel.toggle($event)"
      />
    </OverlayBadge>

    <Popover ref="panel" class="w-96 max-w-[92vw]" :pt="{ content: { class: '!p-0' } }">
      <div class="flex items-center justify-between border-b border-line px-4 py-3">
        <div>
          <span class="text-sm font-semibold text-ink">{{ $t('sync.title') }}</span>
          <p class="mt-0.5 text-xs text-mute">
            {{ network.online ? $t('sync.onlineHint') : $t('sync.offlineHint') }}
          </p>
        </div>
        <Button
          v-if="network.online && outbox.pendingCount"
          :label="$t('sync.syncNow')"
          icon="pi pi-sync"
          size="small"
          text
          :loading="outbox.syncing"
          @click="outbox.sync()"
        />
      </div>

      <ul class="max-h-[55vh] divide-y divide-line overflow-y-auto">
        <li v-for="item in outbox.visible" :key="item.uuid" class="px-4 py-2.5">
          <div class="flex items-start gap-2.5">
            <i
              :class="[statusOf(item).icon, statusOf(item).cls]"
              class="mt-0.5 shrink-0 text-sm"
              aria-hidden="true"
            />
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium text-ink">{{ item.label }}</p>
              <p class="text-xs text-mute">{{ timeAgo(item.createdAt) }}</p>
              <!-- The server's own rejection reason — the conflict, in words. -->
              <p
                v-if="item.status === 'failed'"
                class="mt-1 rounded-lg bg-danger/10 px-2 py-1 text-xs font-medium text-danger"
              >
                {{ item.lastError?.message }}
              </p>
              <div class="mt-1.5 flex gap-3 text-xs">
                <button
                  v-if="item.entityHint?.route"
                  type="button"
                  class="font-medium text-primary-600 hover:underline dark:text-primary-400"
                  @click="view(item)"
                >
                  {{ $t('sync.viewRecord') }}
                </button>
                <button
                  v-if="item.status === 'failed'"
                  type="button"
                  class="font-medium text-primary-600 hover:underline dark:text-primary-400"
                  @click="outbox.retry(item.uuid)"
                >
                  {{ $t('common.retry') }}
                </button>
                <button
                  type="button"
                  class="font-medium text-mute hover:underline"
                  @click="discard(item)"
                >
                  {{ $t('sync.discard') }}
                </button>
              </div>
            </div>
          </div>
        </li>
      </ul>
    </Popover>
  </div>
</template>
