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
    toastError(e.response?.data?.message ?? 'Could not load the payments overview.')
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
  if (ms <= 0) return 'expiring…'
  const h = Math.floor(ms / 3.6e6)
  const m = Math.floor((ms % 3.6e6) / 6e4)
  return `${h}h ${String(m).padStart(2, '0')}m`
}

async function declareSold(h) {
  if (!h.deal_id || !h.item_id) {
    toastError('No open deal on this unit to close — open the project to sell it.')
    return
  }
  const { value, isConfirmed } = await Swal.fire({
    ...BASE_SWAL_OPTS,
    title: `${h.reference} — declare sold 🎉`,
    text: `Agreed price in ${MIL_LABEL} DZD (its boxes included).`,
    input: 'number',
    inputValue: h.price ? dzdToMil(h.price) : undefined,
    inputAttributes: { min: '0', step: '0.01' },
    showCancelButton: true,
    confirmButtonText: 'Mark sold',
    inputValidator: (v) => (!v || Number(v) < 0 ? 'Enter a valid price.' : undefined),
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
    toastError(e.response?.data?.message ?? 'Could not declare the sale.')
  }
}

const t = computed(() => data.value.totals ?? {})
</script>

<template>
  <div>
    <PageHeader
      title="Payments"
      subtitle="Follow up reserved units, interested clients and the instalments to chase — across every project."
    />

    <div class="mb-5 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
      <StatCard label="Reserved" :value="t.reserved ?? 0" icon="pi pi-lock" tone="warning" />
      <StatCard label="Interested" :value="t.interested ?? 0" icon="pi pi-thumbs-up" />
      <StatCard label="Overdue instalments" :value="t.overdue ?? 0" icon="pi pi-exclamation-circle" tone="danger" />
      <StatCard label="Overdue amount" :value="formatMoney(t.overdue_amount ?? 0)" icon="pi pi-money-bill" tone="danger" />
    </div>

    <!-- Reserved: units off the market on a deposit -->
    <SectionCard title="Reserved (holding deposits)" icon="pi pi-lock" class="mb-5" flush>
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
            {{ formatMoney(h.deposit) }} <span class="text-mute">deposit</span>
            <span class="text-mute"> · {{ formatMoney(h.price) }}</span>
          </p>
          <div class="mt-2.5 flex gap-2">
            <RouterLink
              v-if="h.client_id && h.project_id"
              :to="{ name: 'clients.project', params: { id: h.client_id, projectId: h.project_id } }"
              class="flex-1"
            >
              <Button label="Project" icon="pi pi-external-link" size="small" outlined severity="secondary" class="w-full" />
            </RouterLink>
            <Button
              v-if="canDeclare && h.deal_id"
              label="Declare sold"
              icon="pi pi-trophy"
              size="small"
              severity="success"
              class="flex-1"
              @click="declareSold(h)"
            />
          </div>
        </template>
        <template #empty>
          <EmptyState icon="pi pi-lock" title="Nothing reserved" body="No unit is currently reserved on a deposit." />
        </template>
      </NativeList>

      <DataTable v-else :value="data.reserved" :loading="loading" data-key="id" class="text-sm">
        <template #empty>
          <EmptyState icon="pi pi-lock" title="Nothing reserved" body="No unit is currently reserved on a deposit." />
        </template>
        <Column header="Unit">
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
        <Column header="Deposit">
          <template #body="{ data: h }"><span class="num">{{ formatMoney(h.deposit) }}</span></template>
        </Column>
        <Column header="Price">
          <template #body="{ data: h }"><span class="num text-mute">{{ formatMoney(h.price) }}</span></template>
        </Column>
        <Column header="Expires in">
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
                <Button label="Project" icon="pi pi-external-link" size="small" text severity="secondary" />
              </RouterLink>
              <Button
                v-if="canDeclare && h.deal_id"
                label="Declare sold"
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
    <SectionCard title="Interested" icon="pi pi-thumbs-up" class="mb-5" flush>
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
              :label="`${r.interested_count} project${r.interested_count === 1 ? '' : 's'}`"
            />
          </div>
          <p v-if="r.location" class="truncate text-xs text-mute">{{ r.location }}</p>
          <p class="num mt-1 text-sm text-mute">{{ formatMoney(r.price) }}</p>
        </template>
        <template #empty>
          <EmptyState icon="pi pi-thumbs-up" title="No interested clients" body="No unit currently has an interested client." />
        </template>
      </NativeList>

      <DataTable v-else :value="data.interested" :loading="loading" data-key="id" class="text-sm">
        <template #empty>
          <EmptyState icon="pi pi-thumbs-up" title="No interested clients" body="No unit currently has an interested client." />
        </template>
        <Column header="Unit">
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
        <Column header="Held by">
          <template #body="{ data: r }">
            <StatusTag value="interested" :label="`${r.interested_count} project${r.interested_count === 1 ? '' : 's'}`" />
          </template>
        </Column>
        <Column header="Price">
          <template #body="{ data: r }"><span class="num text-mute">{{ formatMoney(r.price) }}</span></template>
        </Column>
      </DataTable>
    </SectionCard>

    <!-- Instalments to chase -->
    <SectionCard title="Instalments to chase" icon="pi pi-calendar-times" flush>
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
          <p class="mt-0.5 text-xs text-mute">Due {{ formatDate(s.due_date) }}</p>
          <p class="num mt-1 text-sm text-ink">
            <span class="font-semibold">{{ formatMoney(s.balance) }}</span>
            <span class="text-mute"> of {{ formatMoney(s.amount) }}</span>
          </p>
        </template>
        <template #empty>
          <EmptyState icon="pi pi-check-circle" title="Nothing due" body="No overdue or upcoming instalments on your projects." />
        </template>
      </NativeList>

      <DataTable v-else :value="data.due" :loading="loading" data-key="id" paginator :rows="15" class="text-sm">
        <template #empty>
          <EmptyState icon="pi pi-check-circle" title="Nothing due" body="No overdue or upcoming instalments on your projects." />
        </template>
        <Column header="Due">
          <template #body="{ data: s }">{{ formatDate(s.due_date) }}</template>
        </Column>
        <Column header="Unit">
          <template #body="{ data: s }">{{ s.unit || '—' }}</template>
        </Column>
        <Column header="Amount">
          <template #body="{ data: s }"><span class="num">{{ formatMoney(s.amount) }}</span></template>
        </Column>
        <Column header="Balance">
          <template #body="{ data: s }"><span class="num font-semibold text-ink">{{ formatMoney(s.balance) }}</span></template>
        </Column>
        <Column header="State">
          <template #body="{ data: s }"><StatusTag :value="s.state" /></template>
        </Column>
        <Column header="">
          <template #body="{ data: s }">
            <RouterLink v-if="s.link" :to="s.link">
              <Button label="Open" icon="pi pi-external-link" size="small" text severity="secondary" />
            </RouterLink>
          </template>
        </Column>
      </DataTable>
    </SectionCard>
  </div>
</template>
