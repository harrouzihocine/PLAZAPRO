<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import Swal from 'sweetalert2'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import StatCard from '@/components/ui/StatCard.vue'
import StatusTag from '@/components/ui/StatusTag.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import NativeList from '@/components/ui/NativeList.vue'
import { useNativePhone } from '@/composables/useNativeMode'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { BASE_SWAL_OPTS, toastError } from '@/composables/useConfirm'
import { paymentsOverviewApi } from '@/features/payments/api'
import { dealsApi } from '@/features/clients/api'
import { dzdToMil, formatMoney, milToDzd, MIL_LABEL } from '@/features/payments/money'
import { useAuthStore } from '@/features/settings/store'
import { formatDate } from '@/utils/format'
import { t } from '@/i18n'

// The payment follow-up hub. Three lists in one place: units Reserved (deposit
// paid, with a live expiry countdown), units with interested clients (the
// "Interested N" pool), and the instalments to chase across the projects the
// user may see. A reserved unit can be turned into a sale from here (same win
// path as the deal panel).
const auth = useAuthStore()
const canDeclare = computed(() => auth.can('deals.manage'))

// Android-shell phones swap the three tables for card lists (short, unpaged —
// the follow-up sets are small by nature; `due` just scrolls).
const nativePhone = useNativePhone()

const data = ref({ reserved: [], interested: [], due: [], totals: {} })
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    data.value = await paymentsOverviewApi.get()
  } catch (e) {
    toastError(e.response?.data?.message ?? t('payments.overviewFailed'))
  } finally {
    loading.value = false
  }
}
onMounted(load)
useRefreshable(load) // pull-to-refresh (APK)

// Live reservation countdown (all rows share one ticking clock).
const now = ref(Date.now())
let ticker
onMounted(() => (ticker = setInterval(() => (now.value = Date.now()), 1000)))
onUnmounted(() => clearInterval(ticker))
function remaining(iso) {
  if (!iso) return null
  const ms = new Date(iso).getTime() - now.value
  if (ms <= 0) return t('payments.expiring')
  const h = Math.floor(ms / 3.6e6)
  const m = Math.floor((ms % 3.6e6) / 6e4)
  return `${h}h ${String(m).padStart(2, '0')}m`
}

async function declareSold(h) {
  if (!h.deal_id || !h.item_id) {
    toastError(t('payments.noOpenDealUnit'))
    return
  }
  const { value, isConfirmed } = await Swal.fire({
    ...BASE_SWAL_OPTS,
    title: t('payments.declareSoldTitle', { ref: h.reference }),
    text: t('deal.agreedPriceLabel', { mil: MIL_LABEL }),
    input: 'number',
    inputValue: h.price ? dzdToMil(h.price) : undefined,
    inputAttributes: { min: '0', step: '0.01' },
    showCancelButton: true,
    confirmButtonText: t('payments.markSold'),
    inputValidator: (v) => (!v || Number(v) < 0 ? t('deal.invalidPrice') : undefined),
    customClass: { confirmButton: 'plaza-swal-confirm', cancelButton: 'plaza-swal-cancel' },
  })
  if (!isConfirmed) return
  try {
    await dealsApi.closeItem(h.deal_id, h.item_id, {
      outcome: 'won',
      agreed_price: milToDzd(value),
    })
    await load()
  } catch (e) {
    toastError(e.response?.data?.message ?? t('payments.declareFailed'))
  }
}

const totals = computed(() => data.value.totals ?? {})
</script>

<template>
  <div>
    <PageHeader
:title="$t('nav.payments')"
      :subtitle="$t('payments.subtitle')"
    />

    <div class="mb-5 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
      <StatCard :label="$t('status.reserved')" :value="totals.reserved ?? 0" icon="pi pi-lock" tone="warning" />
      <StatCard :label="$t('status.interested')" :value="totals.interested ?? 0" icon="pi pi-thumbs-up" />
      <StatCard :label="$t('payments.overdueInstalments')" :value="totals.overdue ?? 0" icon="pi pi-exclamation-circle" tone="danger" />
      <StatCard :label="$t('payments.overdueAmount')" :value="formatMoney(totals.overdue_amount ?? 0)" icon="pi pi-money-bill" tone="danger" />
    </div>

    <!-- Reserved: units off the market on a deposit -->
    <SectionCard :title="$t('payments.reservedTitle')" icon="pi pi-lock" class="mb-5" flush>
      <NativeList v-if="nativePhone" :items="data.reserved" :loading="loading">
        <template #item="{ item: h }">
          <div class="flex items-center justify-between gap-2">
            <RouterLink
              :to="{ name: 'inventory.unit', params: { id: h.id } }"
              class="truncate font-semibold text-ink"
            >
              {{ h.reference }}
            </RouterLink>
            <span class="num shrink-0 text-sm font-semibold text-warning">
              <i class="pi pi-clock text-xs" aria-hidden="true" />
              {{ remaining(h.reserved_expires_at) ?? '—' }}
            </span>
          </div>
          <p v-if="h.location" class="truncate text-xs text-mute">{{ h.location }}</p>
          <p class="num mt-1 text-sm text-ink">
            {{ formatMoney(h.deposit) }} <span class="text-mute">{{ $t('payments.deposit') }}</span>
            <span class="text-mute"> · {{ formatMoney(h.price) }}</span>
          </p>
          <div class="mt-2.5 flex gap-2">
            <RouterLink
              v-if="h.client_id && h.project_id"
              :to="{ name: 'clients.project', params: { id: h.client_id, projectId: h.project_id } }"
              class="flex-1"
            >
              <Button :label="$t('inventory.project')" icon="pi pi-external-link" size="small" outlined severity="secondary" class="w-full" />
            </RouterLink>
            <Button
              v-if="canDeclare && h.deal_id"
:label="$t('payments.declareSold')"
              icon="pi pi-trophy"
              size="small"
              severity="success"
              class="flex-1"
              @click="declareSold(h)"
            />
          </div>
        </template>
        <template #empty>
          <EmptyState icon="pi pi-lock" :title="$t('payments.nothingReserved')" :body="$t('payments.nothingReservedBody')" />
        </template>
      </NativeList>

      <DataTable v-else :value="data.reserved" :loading="loading" data-key="id" class="text-sm">
        <template #empty>
          <EmptyState icon="pi pi-lock" :title="$t('payments.nothingReserved')" :body="$t('payments.nothingReservedBody')" />
        </template>
        <Column :header="$t('project.unit')">
          <template #body="{ data: h }">
            <RouterLink
              :to="{ name: 'inventory.unit', params: { id: h.id } }"
              class="font-medium text-ink hover:underline"
            >
              {{ h.reference }}
            </RouterLink>
            <span v-if="h.location" class="block text-xs text-mute">{{ h.location }}</span>
          </template>
        </Column>
        <Column :header="$t('payments.deposit')">
          <template #body="{ data: h }"><span class="num">{{ formatMoney(h.deposit) }}</span></template>
        </Column>
        <Column :header="$t('inventory.price')">
          <template #body="{ data: h }"><span class="num text-mute">{{ formatMoney(h.price) }}</span></template>
        </Column>
        <Column :header="$t('payments.expiresIn')">
          <template #body="{ data: h }">
            <span class="num font-semibold text-warning">{{ remaining(h.reserved_expires_at) ?? '—' }}</span>
          </template>
        </Column>
        <Column header="">
          <template #body="{ data: h }">
            <div class="flex justify-end gap-1.5">
              <RouterLink
                v-if="h.client_id && h.project_id"
                :to="{ name: 'clients.project', params: { id: h.client_id, projectId: h.project_id } }"
              >
                <Button :label="$t('inventory.project')" icon="pi pi-external-link" size="small" text severity="secondary" />
              </RouterLink>
              <Button
                v-if="canDeclare && h.deal_id"
  :label="$t('payments.declareSold')"
                icon="pi pi-trophy"
                size="small"
                severity="success"
                @click="declareSold(h)"
              />
            </div>
          </template>
        </Column>
      </DataTable>
    </SectionCard>

    <!-- Interested: the "Interested N" pool -->
    <SectionCard :title="$t('status.interested')" icon="pi pi-thumbs-up" class="mb-5" flush>
      <NativeList v-if="nativePhone" :items="data.interested" :loading="loading">
        <template #item="{ item: r }">
          <div class="flex items-center justify-between gap-2">
            <RouterLink
              :to="{ name: 'inventory.unit', params: { id: r.id } }"
              class="truncate font-semibold text-ink"
            >
              {{ r.reference }}
            </RouterLink>
            <StatusTag
              value="interested"
              :label="$t('inventory.nProjects', { n: r.interested_count })"
            />
          </div>
          <p v-if="r.location" class="truncate text-xs text-mute">{{ r.location }}</p>
          <p class="num mt-1 text-sm text-mute">{{ formatMoney(r.price) }}</p>
        </template>
        <template #empty>
          <EmptyState icon="pi pi-thumbs-up" :title="$t('payments.noInterested')" :body="$t('payments.noInterestedBody')" />
        </template>
      </NativeList>

      <DataTable v-else :value="data.interested" :loading="loading" data-key="id" class="text-sm">
        <template #empty>
          <EmptyState icon="pi pi-thumbs-up" :title="$t('payments.noInterested')" :body="$t('payments.noInterestedBody')" />
        </template>
        <Column :header="$t('project.unit')">
          <template #body="{ data: r }">
            <RouterLink
              :to="{ name: 'inventory.unit', params: { id: r.id } }"
              class="font-medium text-ink hover:underline"
            >
              {{ r.reference }}
            </RouterLink>
            <span v-if="r.location" class="block text-xs text-mute">{{ r.location }}</span>
          </template>
        </Column>
        <Column :header="$t('payments.heldBy')">
          <template #body="{ data: r }">
            <StatusTag value="interested" :label="$t('inventory.nProjects', { n: r.interested_count })" />
          </template>
        </Column>
        <Column :header="$t('inventory.price')">
          <template #body="{ data: r }"><span class="num text-mute">{{ formatMoney(r.price) }}</span></template>
        </Column>
      </DataTable>
    </SectionCard>

    <!-- Instalments to chase -->
    <SectionCard :title="$t('payments.instalmentsToChase')" icon="pi pi-calendar-times" flush>
      <NativeList
        v-if="nativePhone"
        :items="data.due"
        :loading="loading"
        clickable
        @item-click="(s) => s.link && $router.push(s.link)"
      >
        <template #item="{ item: s }">
          <div class="flex items-center justify-between gap-2">
            <span class="truncate text-sm font-medium text-ink">{{ s.unit || '—' }}</span>
            <StatusTag :value="s.state" />
          </div>
          <p class="mt-0.5 text-xs text-mute">{{ $t('pipeline.due', { date: formatDate(s.due_date) }) }}</p>
          <p class="num mt-1 text-sm text-ink">
            <span class="font-semibold">{{ formatMoney(s.balance) }}</span>
            <span class="text-mute"> / {{ formatMoney(s.amount) }}</span>
          </p>
        </template>
        <template #empty>
          <EmptyState icon="pi pi-check-circle" :title="$t('payments.nothingDue')" :body="$t('payments.nothingDueBody')" />
        </template>
      </NativeList>

      <DataTable v-else :value="data.due" :loading="loading" data-key="id" paginator :rows="15" class="text-sm">
        <template #empty>
          <EmptyState icon="pi pi-check-circle" :title="$t('payments.nothingDue')" :body="$t('payments.nothingDueBody')" />
        </template>
        <Column :header="$t('pipeline.dueLabel')">
          <template #body="{ data: s }">{{ formatDate(s.due_date) }}</template>
        </Column>
        <Column :header="$t('project.unit')">
          <template #body="{ data: s }">{{ s.unit || '—' }}</template>
        </Column>
        <Column :header="$t('payments.amount')">
          <template #body="{ data: s }"><span class="num">{{ formatMoney(s.amount) }}</span></template>
        </Column>
        <Column :header="$t('inventory.balance')">
          <template #body="{ data: s }"><span class="num font-semibold text-ink">{{ formatMoney(s.balance) }}</span></template>
        </Column>
        <Column :header="$t('common.status')">
          <template #body="{ data: s }"><StatusTag :value="s.state" /></template>
        </Column>
        <Column header="">
          <template #body="{ data: s }">
            <RouterLink v-if="s.link" :to="s.link">
              <Button :label="$t('common.open')" icon="pi pi-external-link" size="small" text severity="secondary" />
            </RouterLink>
          </template>
        </Column>
      </DataTable>
    </SectionCard>
  </div>
</template>
