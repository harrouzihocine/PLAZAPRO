<script setup>
import { computed, onMounted, ref } from 'vue'
import BaseModal from '@/components/base/BaseModal.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import { clientsApi } from '@/features/clients/api'
import { mediaShareApi } from '@/features/inventory/api'
import { buildMediaShareText } from '@/features/inventory/mediaShare'
import { officeProfile } from '@/features/pipeline/officeInvite'
import { copyToClipboard } from '@/composables/useClipboard'
import { toastError } from '@/composables/useConfirm'
import { formatPhone } from '@/data/countryCodes'
import { formatDate } from '@/utils/format'
import { t } from '@/i18n'

// "Send these photos to a client on WhatsApp": search the caller's own book
// (the /clients listing is server-scoped by Client::visibleTo, exactly like
// the office-invite quick action), pick one, and the share link is minted on
// the spot — the WhatsApp button then opens wa.me with the composed message.
// Copy-link covers clients reached over other channels (or masked phones).
const props = defineProps({
  mediableType: { type: String, required: true }, // 'locations' | 'units'
  mediableId: { type: [String, Number], required: true },
  mediaIds: { type: Array, required: true },
})
const emit = defineEmits(['close', 'done'])

const query = ref('')
const results = ref([])
const searching = ref(false)
const selected = ref(null)
const creating = ref(false)
const share = ref(null)
let debounce = null

const profile = ref({ company: '', address: '', maps: '', phone: '' })
onMounted(async () => {
  profile.value = await officeProfile()
})

function onQuery(value) {
  query.value = value
  clearTimeout(debounce)
  const term = value.trim()
  if (term.length < 2) {
    results.value = []
    return
  }
  debounce = setTimeout(async () => {
    searching.value = true
    try {
      const res = await clientsApi.list({ search: term, per_page: 8 })
      results.value = res.items ?? []
    } catch {
      results.value = []
    } finally {
      searching.value = false
    }
  }, 250)
}

// Picking the client IS the confirmation: the link is minted immediately so
// the ready panel's WhatsApp anchor is a plain user-gesture navigation (an
// async window.open after the tap would trip popup blockers).
async function pick(client) {
  selected.value = client
  creating.value = true
  try {
    share.value = await mediaShareApi.create({
      mediable_type: props.mediableType,
      mediable_id: Number(props.mediableId),
      media_ids: props.mediaIds,
      client_id: client.id,
    })
  } catch (e) {
    toastError(e.response?.data?.message)
    selected.value = null
  } finally {
    creating.value = false
  }
}

function changeClient() {
  selected.value = null
  share.value = null
}

// Same wa.me convention as everywhere else: digits only (phones are E.164).
const digits = computed(() => (selected.value?.phone || '').replace(/\D/g, ''))
const hasPhone = computed(() => digits.value.length > 0)

const message = computed(() =>
  share.value
    ? buildMediaShareText({
        name: selected.value?.full_name,
        title: share.value.title,
        url: share.value.url,
        profile: profile.value,
      })
    : '',
)
const whatsappUrl = computed(
  () => `https://wa.me/${digits.value}?text=${encodeURIComponent(message.value)}`,
)

async function copyLink() {
  await copyToClipboard(share.value.url, t('mediaShare.linkCopied'))
  emit('done', 'copy')
}

const rowClass =
  'flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-sm text-ink transition-colors hover:bg-surface-50 dark:hover:bg-surface-800'
</script>

<template>
  <BaseModal :title="$t('mediaShare.title')" size="max-w-md" @close="emit('close')">
    <div class="space-y-4">
      <p class="text-sm text-mute">
        {{ $t('mediaShare.summary', { n: mediaIds.length }) }}
      </p>

      <!-- Step 1: find the client (own book — server-scoped). -->
      <template v-if="!selected">
        <BaseInput
          :model-value="query"
          :label="$t('mediaShare.pickClient')"
          :placeholder="$t('clients.searchPlaceholder')"
          @update:model-value="onQuery"
        />
        <p v-if="searching" class="text-sm text-mute">{{ $t('common.loading') }}</p>
        <ul v-else-if="results.length" class="divide-y divide-line rounded-lg border border-line">
          <li v-for="c in results" :key="c.id">
            <button
              type="button"
              class="flex w-full items-center justify-between gap-2 px-3 py-2.5 text-start text-sm transition-colors hover:bg-surface-50 dark:hover:bg-surface-800"
              @click="pick(c)"
            >
              <span class="truncate font-medium text-ink">{{ c.full_name }}</span>
              <span class="num ltr-data shrink-0 text-xs text-mute">{{ formatPhone(c.phone) }}</span>
            </button>
          </li>
        </ul>
        <p v-else-if="query.trim().length >= 2" class="text-sm text-mute">
          {{ $t('common.noResults') }}
        </p>
      </template>

      <p v-else-if="creating" class="text-sm text-mute">
        <i class="pi pi-spin pi-spinner me-1" aria-hidden="true" /> {{ $t('mediaShare.creating') }}
      </p>

      <!-- Step 2: the link is ready — hand it over. -->
      <template v-else-if="share">
        <div class="flex items-center justify-between gap-2 rounded-lg border border-line px-3 py-2">
          <span class="min-w-0">
            <span class="block truncate font-medium text-ink">{{ selected.full_name }}</span>
            <span class="num ltr-data text-xs text-mute">{{ formatPhone(selected.phone) }}</span>
          </span>
          <button
            type="button"
            class="shrink-0 text-sm text-primary-600 hover:underline dark:text-primary-400"
            @click="changeClient"
          >
            {{ $t('officeInvite.changeClient') }}
          </button>
        </div>

        <div class="space-y-1">
          <a
            v-if="hasPhone"
            :href="whatsappUrl"
            target="_blank"
            rel="noopener"
            :class="rowClass"
            @click="emit('done', 'whatsapp')"
          >
            <i class="pi pi-whatsapp text-green-600 dark:text-green-400" aria-hidden="true" />
            {{ $t('mediaShare.whatsapp') }}
          </a>
          <p v-else class="flex items-center gap-2 px-2 py-1.5 text-xs text-mute">
            <i class="pi pi-exclamation-triangle" aria-hidden="true" />
            {{ $t('officeInvite.noPhone') }}
          </p>
          <button type="button" :class="rowClass" @click="copyLink">
            <i class="pi pi-link text-mute" aria-hidden="true" />
            {{ $t('mediaShare.copyLink') }}
          </button>
        </div>

        <p class="text-xs text-mute">
          {{ $t('mediaShare.expires', { date: formatDate(share.expires_at) }) }}
        </p>
      </template>
    </div>
  </BaseModal>
</template>
