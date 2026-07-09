<script setup>
import { onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import StatusTag from '@/components/ui/StatusTag.vue'
import { toastError } from '@/composables/useConfirm'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { locationsApi, reservationsApi } from '@/features/inventory/api'
import { formatMoney } from '@/features/payments/money'
import { formatDateTime, timeAgo } from '@/utils/format'
import { t } from '@/i18n'

// The reservation follow-up board: every reserved/held unit with its ordered
// queue — the page an agent opens to tell a client "you are 2nd in line; if
// the 1st doesn't buy, it goes to you". Entries whose project the viewer
// cannot see stay position + agent only (the backend masks the client).
const units = ref([])
const locations = ref([])
const loading = ref(true)
const filters = reactive({ status: '', location_id: '', search: '' })

async function load() {
  loading.value = true
  try {
    const params = {}
    for (const [k, v] of Object.entries(filters)) {
      if (v !== '' && v != null) params[k] = v
    }
    units.value = await reservationsApi.queues(params)
  } catch (e) {
    toastError(e.response?.data?.message ?? t('reservations.loadFailed'))
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  load()
  locations.value = await locationsApi.list()
})
useRefreshable(load) // pull-to-refresh + reconnect self-heal

// The search field fires on every keystroke — debounce the reload.
let searchTimer = null
function searchDebounced() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(load, 400)
}

const statusOptions = () => [
  { value: 'reserved', label: t('status.reserved') },
  { value: 'interested', label: t('status.interested') },
]

const unitLine = (u) => {
  // Both finish offers, tagged, so the quoted number is never ambiguous.
  const prices = [
    u.price_semi_fini != null
      ? `${t('inventory.finishSemiShort')} ${formatMoney(u.price_semi_fini)}`
      : null,
    u.price_fini != null ? `${t('inventory.finishFiniShort')} ${formatMoney(u.price_fini)}` : null,
  ].filter(Boolean)

  return [
    u.location?.name,
    u.room_number,
    u.floor,
    u.area_sqm ? `${u.area_sqm} m²` : null,
    prices.length ? prices.join(' / ') : null,
  ]
    .filter(Boolean)
    .join(' · ')
}
</script>

<template>
  <div>
    <PageHeader
      :title="$t('reservations.title')"
      :subtitle="$t('reservations.subtitle')"
    />

    <!-- Filters: status / project / reference search. -->
    <div class="mb-4 grid gap-3 sm:grid-cols-3">
      <BaseSelect
        v-model="filters.status"
        :label="$t('inventory.saleStatus')"
        :placeholder="$t('common.all')"
        :options="statusOptions()"
        @update:model-value="load()"
      />
      <BaseSelect
        v-model="filters.location_id"
        :label="$t('inventory.project')"
        :placeholder="$t('inventory.allProjects')"
        :options="locations.map((l) => ({ value: l.id, label: `${l.code} · ${l.name}` }))"
        @update:model-value="load()"
      />
      <BaseInput
        v-model="filters.search"
        :label="$t('common.search')"
        :placeholder="$t('reservations.searchPlaceholder')"
        @update:model-value="searchDebounced()"
      />
    </div>

    <p v-if="loading" class="py-6 text-center text-sm text-mute">{{ $t('common.loading') }}</p>

    <EmptyState
      v-else-if="!units.length"
      icon="pi pi-bookmark"
      :title="$t('reservations.emptyTitle')"
      :body="$t('reservations.emptyBody')"
    />

    <div v-else class="space-y-4">
      <SectionCard v-for="u in units" :key="u.id" flush>
        <template #header>
          <div class="flex min-w-0 flex-wrap items-center gap-2">
            <RouterLink
              :to="`/inventory/units/${u.id}`"
              class="text-sm font-semibold text-ink transition-colors hover:text-primary"
            >
              {{ u.reference }}
            </RouterLink>
            <StatusTag :value="u.sale_status" />
            <span class="truncate text-xs text-mute">{{ unitLine(u) }}</span>
          </div>
        </template>
        <template #actions>
          <!-- The deposit clock — how long before the Reserved lock lapses. -->
          <span
            v-if="u.reserved_expires_at"
            class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-300"
          >
            <i class="pi pi-clock text-[10px]" aria-hidden="true" />
            {{ $t('reservations.expires') }} {{ formatDateTime(u.reserved_expires_at) }}
          </span>
        </template>

        <ul v-if="u.queue.length" class="divide-y divide-line">
          <li
            v-for="entry in u.queue"
            :key="entry.position"
            class="flex items-center gap-3 px-4 py-2.5 sm:px-5"
          >
            <!-- Queue place — the number the agent quotes to the client. -->
            <span
              class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold"
              :class="
                entry.position === 1
                  ? 'bg-primary text-primary-contrast'
                  : 'bg-highlight text-ink'
              "
            >
              {{ entry.position }}
            </span>

            <div class="min-w-0 flex-1">
              <p class="truncate text-sm text-ink">
                <RouterLink
                  v-if="entry.visible && entry.client_project_id"
                  :to="`/clients/${entry.client_id}/projects/${entry.client_project_id}`"
                  class="font-medium transition-colors hover:text-primary"
                >
                  {{ entry.client_name || $t('reservations.unnamedClient') }}
                </RouterLink>
                <span v-else class="italic text-mute">{{ $t('reservations.maskedClient') }}</span>
                <span
                  v-if="entry.is_deposit"
                  class="ms-2 inline-flex items-center gap-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-300"
                >
                  <i class="pi pi-lock text-[9px]" aria-hidden="true" />
                  {{ $t('reservations.deposit') }}
                </span>
              </p>
              <p class="truncate text-xs text-mute">
                <template v-if="entry.agent">{{ $t('reservations.heldBy', { agent: entry.agent }) }} · </template>
                {{ timeAgo(entry.held_at) }}
                <template v-if="entry.expires_at"> · {{ $t('reservations.holdExpires') }} {{ formatDateTime(entry.expires_at) }}</template>
              </p>
            </div>
          </li>
        </ul>
        <p v-else class="px-4 py-3 text-sm text-mute sm:px-5">
          {{ $t('reservations.noQueue') }}
        </p>
      </SectionCard>
    </div>
  </div>
</template>
