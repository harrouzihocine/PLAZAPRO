<script setup>
import Swal from 'sweetalert2'
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseModal from '@/components/base/BaseModal.vue'
import BaseMultiSelect from '@/components/base/BaseMultiSelect.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'
import MoneyInput from '@/components/base/MoneyInput.vue'
import TimeField from '@/components/base/TimeField.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import StatusTag from '@/components/ui/StatusTag.vue'
import { dealsApi, staffApi } from '@/features/clients/api'
import { useClientsStore } from '@/features/clients/clientsStore'
import SaleStatusBadge from '@/features/inventory/components/SaleStatusBadge.vue'
import UnitBoxPicker from '@/features/inventory/components/UnitBoxPicker.vue'
import { useAuthStore } from '@/features/settings/store'
import { appSettingsApi } from '@/features/settings/api'
import { useDynamicList, itemLabel } from '@/composables/useDynamicList'
import { versementsApi } from '@/features/payments/api'
import { useNetworkStore } from '@/features/offline/networkStore'
import { BASE_SWAL_OPTS, toastError, toastSuccess } from '@/composables/useConfirm'
import { dateInputValue, formatDateTime, timeInputValue, todayInput } from '@/utils/format'
import { dzdToMil, formatMoney, milToDzd, MIL_LABEL } from '@/features/payments/money'
import { t } from '@/i18n'

// THE deal on a project — each APARTMENT tracked alone: its own card, its own
// boxes (grouped under it), its own close. Winning an apartment asks for ITS
// agreed price (covers its boxes; payments then track it alone); releasing one
// returns it (and its boxes) to inventory. The deal resolves itself when the
// last apartment is decided — if nothing was won, the lost deal must resolve
// into a reopen or an archive.
const props = defineProps({
  clientId: { type: [String, Number], required: true },
  projectId: { type: [String, Number], required: true },
})
const emit = defineEmits(['changed'])
const store = useClientsStore()
const auth = useAuthStore()

const canClose = () => auth.can('deals.manage')
const canEditBoxes = () => auth.can('visits.conduct')
const canRecord = () => auth.can('versements.record')

const { items: methods } = useDynamicList('payment_methods')

// --- Record a holding deposit → the apartment goes Reserved -----------------
const depositFlow = ref({
  open: false,
  unit: null,
  amount: '',
  paid_on: todayInput(),
  method_id: '',
  back_date: '',
  back_time: '',
})
// Guards double-submit: the deposit records a versement directly (not via a
// store action), so store.saving never covers it — a double-click would book
// two deposits without this.
const depositSubmitting = ref(false)

// Only the deposit that flips the unit to Reserved sets the back-to-market
// deadline — a later payment on an already-Reserved unit never moves it
// (matching the backend), so the picker hides then.
const depositArmsReservation = computed(
  () => depositFlow.value.unit && depositFlow.value.unit.sale_status !== 'reserved',
)

// The global window (app setting) seeds the deadline so the agent sees the
// concrete moment and adjusts it per deal. Fetched once; if the fetch fails the
// fields stay empty and the backend applies the same default itself.
let cachedReservedHoldHours = null
async function defaultReservedDeadline() {
  if (cachedReservedHoldHours === null) {
    try {
      cachedReservedHoldHours = Number((await appSettingsApi.get()).reserved_hold_hours) || 72
    } catch {
      return null
    }
  }
  return new Date(Date.now() + cachedReservedHoldHours * 3600000)
}

function openDeposit(unit) {
  depositFlow.value = {
    open: true,
    unit,
    amount: '',
    paid_on: todayInput(),
    method_id: '',
    back_date: '',
    back_time: '',
  }
  if (unit.sale_status !== 'reserved') {
    defaultReservedDeadline().then((d) => {
      const f = depositFlow.value
      // Don't clobber a deadline the agent already started typing.
      if (d && f.open && f.unit === unit && !f.back_date) {
        f.back_date = dateInputValue(d)
        f.back_time = timeInputValue(d)
      }
    })
  }
}

async function submitDeposit() {
  // Financial writes never queue offline — the reservation lock must be live.
  if (!useNetworkStore().requireOnline(t('deal.offlineDeposit'))) {
    return
  }
  const f = depositFlow.value
  if (!f.amount || !f.method_id) {
    toastError(t('deal.depositMissingFields'))
    return
  }
  if (depositSubmitting.value) return
  depositSubmitting.value = true
  try {
    await versementsApi.record(props.projectId, {
      unit_id: f.unit.id,
      amount: f.amount,
      paid_on: f.paid_on,
      method_id: f.method_id,
      // Per-deal Reserved window (only meaningful on the arming deposit);
      // omitted = the global default window.
      ...(depositArmsReservation.value && f.back_date
        ? { reserved_until: `${f.back_date} ${f.back_time || '00:00'}` }
        : {}),
    })
    depositFlow.value.open = false
    toastSuccess(t('deal.depositRecorded', { ref: f.unit.reference }))
    // Reload the deals so the apartment card shows the deposit + Reserved state.
    await store.loadDeals(props.projectId)
    emit('changed')
  } catch (e) {
    toastError(e.response?.data?.message ?? t('deal.depositFailed'))
  } finally {
    depositSubmitting.value = false
  }
}

const deals = computed(() => store.deals[props.projectId] ?? [])
const openDeal = computed(() => deals.value.find((d) => d.state === 'open'))
const closedDeals = computed(() => deals.value.filter((d) => d.state !== 'open'))

const unitLine = (u) =>
  [u.reference, u.type, u.floor, u.area_sqm ? `${u.area_sqm} m²` : null, u.location]
    .filter(Boolean)
    .join(' · ')

// The boxes riding with one apartment (parent_item_id); legacy boxes (created
// before per-apartment grouping) have no parent and are shown at deal level.
const boxesOf = (deal, unit) => (deal.boxes ?? []).filter((b) => b.parent_item_id === unit.item_id)
const orphanBoxes = (deal) => (deal.boxes ?? []).filter((b) => b.parent_item_id == null)

// Suggested agreed price for ONE apartment = its list price + its boxes'.
const suggestedFor = (deal, unit) =>
  [unit, ...boxesOf(deal, unit)].reduce((sum, p) => sum + Number(p.price ?? 0), 0)

const openUnits = (deal) => (deal.units ?? []).filter((u) => u.state === 'open')
const wonUnits = (deal) => (deal.units ?? []).filter((u) => u.state === 'won')

onMounted(() => store.loadDeals(props.projectId))

// --- Win ONE apartment: price + who deserves the credit --------------------
// The sale/in-site pickers pool from everyone who worked the project (its
// timeline); "others" pools from all staff. Opening loads that pool + defaults.
const participants = ref([]) // [{id, name}] — sale + in-site options
const allStaff = ref([]) // [{id, name}] — "others" options
const winFlow = ref({
  open: false,
  deal: null,
  unit: null,
  price: '',
  sale: [],
  insite: [],
  other: [],
  loading: false,
})

const participantOptions = computed(() =>
  participants.value.map((p) => ({ value: p.id, label: p.name })),
)
const staffOptions = computed(() => allStaff.value.map((s) => ({ value: s.id, label: s.name })))

async function winUnit(deal, unit) {
  winFlow.value = {
    open: true,
    deal,
    unit,
    price: dzdToMil(suggestedFor(deal, unit)) || '',
    sale: [],
    insite: [],
    other: [],
    loading: true,
  }
  try {
    const [pool, staff] = await Promise.all([
      dealsApi.participants(props.projectId),
      staffApi.list(),
    ])
    participants.value = pool.participants ?? []
    allStaff.value = staff ?? []
    // Sensible defaults the user can adjust.
    winFlow.value.insite = pool.insite_agent_ids ?? []
    winFlow.value.sale = pool.sale_agent_ids ?? []
  } catch {
    /* the pickers just stay empty — the price still works */
  } finally {
    winFlow.value.loading = false
  }
}

async function confirmWin() {
  const f = winFlow.value
  if (!f.price || Number(f.price) < 0) {
    toastError(t('deal.invalidPrice'))
    return
  }
  await store.closeDealItem(props.clientId, props.projectId, f.deal.id, f.unit.item_id, {
    outcome: 'won',
    agreed_price: milToDzd(f.price),
    sale_agent_ids: f.sale,
    insite_agent_ids: f.insite,
    other_agent_ids: f.other,
  })
  winFlow.value.open = false
  emit('changed')
}

// --- Release (lose) an apartment / the whole rest of the deal --------------
// The project only needs a resolution (reopen / archive) when NOTHING remains
// committed on it — no apartment open or won on ANY of its deals. While
// another deal (or apartment) is still in play, a release is just a release:
// the work continues over there.
const lostFlow = ref({ open: false, deal: null, unit: null, resolution: 'reopen', note: '' })

// Anything still committed once `unit` on `deal` closes? (this deal's other
// apartments and every other deal count — open or won.)
const stillEngagedAfter = (deal, unit) =>
  deals.value.some((d) =>
    (d.units ?? []).some(
      (u) =>
        !(d.id === deal.id && unit && u.item_id === unit.item_id) &&
        ['open', 'won'].includes(u.state),
    ),
  )

function releaseUnit(deal, unit) {
  if (!stillEngagedAfter(deal, unit)) {
    lostFlow.value = { open: true, deal, unit, resolution: 'reopen', note: '' }
    return
  }
  confirmRelease(deal, unit)
}

async function confirmRelease(deal, unit) {
  const { isConfirmed } = await Swal.fire({
    ...BASE_SWAL_OPTS,
    title: t('deal.releaseTitle', { ref: unit.reference }),
    text: t('deal.releaseText'),
    showCancelButton: true,
    confirmButtonText: t('deal.release'),
    customClass: { confirmButton: 'plaza-swal-confirm', cancelButton: 'plaza-swal-cancel' },
  })
  if (!isConfirmed) return
  await store.closeDealItem(props.clientId, props.projectId, deal.id, unit.item_id, {
    outcome: 'lost',
  })
  emit('changed')
}

function closeLostAll(deal) {
  const engagedElsewhere =
    wonUnits(deal).length > 0 ||
    deals.value.some(
      (d) => d.id !== deal.id && (d.units ?? []).some((u) => ['open', 'won'].includes(u.state)),
    )
  if (!engagedElsewhere) {
    lostFlow.value = { open: true, deal, unit: null, resolution: 'reopen', note: '' }
    return
  }
  confirmLoseWholeDeal(deal)
}

// Cancel THIS deal only — the project continues on its other deal(s).
async function confirmLoseWholeDeal(deal) {
  const { isConfirmed } = await Swal.fire({
    ...BASE_SWAL_OPTS,
    title: t('deal.releaseAllTitle'),
    text: t('deal.releaseAllText'),
    showCancelButton: true,
    confirmButtonText: t('deal.releaseAll'),
    customClass: { confirmButton: 'plaza-swal-confirm', cancelButton: 'plaza-swal-cancel' },
  })
  if (!isConfirmed) return
  await store.closeDeal(props.clientId, props.projectId, deal.id, { outcome: 'lost' })
  emit('changed')
}

const lostReady = computed(
  () => lostFlow.value.resolution === 'reopen' || !!lostFlow.value.note.trim(),
)

async function confirmLost() {
  if (!lostReady.value) return
  const { deal, unit, resolution, note } = lostFlow.value
  const payload = { outcome: 'lost', resolution, note: note.trim() || null }
  if (unit) {
    await store.closeDealItem(props.clientId, props.projectId, deal.id, unit.item_id, payload)
  } else {
    await store.closeDeal(props.clientId, props.projectId, deal.id, payload)
  }
  lostFlow.value.open = false
  emit('changed')
}

// --- Release a WON apartment (the sale fell through, even after the win) ---
// The apartment and its boxes return to the market; recorded payments stay as
// refundable history. Releasing the LAST won apartment (nothing else won or
// still open anywhere) un-wins the project — ask how it continues.
const releaseWonFlow = ref({ open: false, deal: null, unit: null, resolution: 'reopen', note: '' })

const releaseWonReady = computed(
  () => releaseWonFlow.value.resolution === 'reopen' || !!releaseWonFlow.value.note.trim(),
)

function releaseWon(deal, unit) {
  if (!stillEngagedAfter(deal, unit)) {
    releaseWonFlow.value = { open: true, deal, unit, resolution: 'reopen', note: '' }
    return
  }
  confirmReleaseWon(deal, unit)
}

async function confirmReleaseWon(deal, unit) {
  const { isConfirmed } = await Swal.fire({
    ...BASE_SWAL_OPTS,
    title: t('deal.releaseTitle', { ref: unit.reference }),
    text: t('deal.releaseWonText'),
    showCancelButton: true,
    confirmButtonText: t('deal.release'),
    customClass: { confirmButton: 'plaza-swal-confirm', cancelButton: 'plaza-swal-cancel' },
  })
  if (!isConfirmed) return
  await store.releaseDealItem(props.clientId, props.projectId, deal.id, unit.item_id, {})
  emit('changed')
}

async function confirmReleaseWonResolved() {
  if (!releaseWonReady.value) return
  const { deal, unit, resolution, note } = releaseWonFlow.value
  await store.releaseDealItem(props.clientId, props.projectId, deal.id, unit.item_id, {
    resolution,
    note: note.trim() || null,
  })
  releaseWonFlow.value.open = false
  emit('changed')
}

// --- Sell EXTRA boxes onto a WON apartment (client comes back for parking) --
// The boxes sell right onto the won apartment (sold + linked) and its agreed
// price grows by the addition — no release-and-rewin dance.
const addBoxFlow = ref({ open: false, deal: null, unit: null, selected: [], price: '' })

function openAddBoxes(deal, unit) {
  addBoxFlow.value = { open: true, deal, unit, selected: [], price: '' }
}

async function submitAddBoxes() {
  const { deal, unit, selected, price } = addBoxFlow.value
  if (!selected.length) return
  await store.addDealBoxes(props.clientId, props.projectId, deal.id, unit.item_id, {
    box_ids: selected,
    added_price: price !== '' && price != null ? milToDzd(price) : null,
  })
  addBoxFlow.value.open = false
  emit('changed')
}

// --- Boxes editor, per apartment (open deal, apartment still open) ---------
const boxEditor = ref({ open: false, deal: null, unit: null, selected: [], current: [] })

function openBoxEditor(deal, unit) {
  const current = boxesOf(deal, unit)
  boxEditor.value = {
    open: true,
    deal,
    unit,
    selected: current.map((b) => b.id),
    current,
  }
}

async function saveBoxes() {
  try {
    await store.syncDealUnitBoxes(
      props.projectId,
      boxEditor.value.deal.id,
      boxEditor.value.unit.item_id,
      boxEditor.value.selected,
    )
    boxEditor.value.open = false
  } catch {
    /* toast raised by the store */
  }
}
</script>

<template>
  <SectionCard v-if="deals.length" :title="$t('deal.title')" icon="pi pi-briefcase">
    <div class="space-y-3">
      <div
        v-for="deal in deals"
        :key="deal.id"
        class="rounded-lg border p-3"
        :class="
          deal.state === 'open'
            ? 'border-primary-300 bg-primary-50/50 dark:border-primary-500/30 dark:bg-primary-500/5'
            : 'border-line'
        "
      >
        <div class="mb-2 flex items-center justify-between gap-2">
          <StatusTag :value="deal.state" />
          <span v-if="deal.total_price" class="num text-sm font-semibold text-ink">
            {{ formatMoney(deal.total_price) }}
          </span>
        </div>

        <!-- One card per APARTMENT: its state, its boxes, its own close. -->
        <div class="space-y-2">
          <div
            v-for="u in deal.units"
            :key="'u' + u.item_id"
            class="rounded-lg border border-line bg-card p-2.5 text-sm"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <span class="flex min-w-0 items-center gap-2">
                <i class="pi pi-home shrink-0 text-mute" aria-hidden="true" />
                <span class="truncate text-ink">{{ unitLine(u) }}</span>
                <StatusTag v-if="u.state !== 'open'" :value="u.state" />
                <!-- A deposit Reserved this open apartment. -->
                <SaleStatusBadge
                  v-if="u.state === 'open' && u.sale_status === 'reserved'"
                  status="reserved"
                />
              </span>
              <span
                class="num shrink-0 text-xs"
                :class="u.agreed_price ? 'font-semibold text-ink' : 'text-mute'"
              >
                {{ formatMoney(u.agreed_price ?? u.price) }}
              </span>
            </div>

            <!-- Holding deposit collected for this apartment (pre-sale). -->
            <p
              v-if="u.state === 'open' && Number(u.collected) > 0"
              class="mt-1 flex items-center gap-1.5 text-xs text-mute"
            >
              <i class="pi pi-wallet text-[10px] text-primary-500" aria-hidden="true" />
              {{ $t('deal.depositPaid') }}
              <span class="num font-semibold text-ink">{{ formatMoney(u.collected) }}</span>
              <span v-if="u.sale_status === 'reserved' && u.reserved_expires_at">
                · {{ $t('deal.reservedUntil', { date: formatDateTime(u.reserved_expires_at) }) }}
              </span>
            </p>

            <!-- Who was credited for the sale (won apartment). -->
            <div
              v-if="u.state === 'won' && u.credited"
              class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-mute"
            >
              <span v-if="u.credited.sale.length">
                <i class="pi pi-megaphone text-[10px] text-primary-500" aria-hidden="true" />
                {{ $t('deal.creditSale') }} <span class="text-ink">{{ u.credited.sale.join(', ') }}</span>
              </span>
              <span v-if="u.credited.insite.length">
                <i class="pi pi-map-marker text-[10px] text-primary-500" aria-hidden="true" />
                {{ $t('deal.creditInsite') }} <span class="text-ink">{{ u.credited.insite.join(', ') }}</span>
              </span>
              <span v-if="u.credited.other.length">
                <i class="pi pi-users text-[10px] text-primary-500" aria-hidden="true" />
                {{ $t('deal.creditOthers') }} <span class="text-ink">{{ u.credited.other.join(', ') }}</span>
              </span>
            </div>

            <!-- The apartment's boxes, right under it. -->
            <ul v-if="boxesOf(deal, u).length" class="mt-1.5 space-y-1 border-t border-line pt-1.5">
              <li
                v-for="b in boxesOf(deal, u)"
                :key="'b' + b.item_id"
                class="flex items-center justify-between gap-2 ps-6 text-xs"
              >
                <span class="flex min-w-0 items-center gap-1.5 text-ink">
                  <i class="pi pi-car shrink-0 text-mute" aria-hidden="true" />
                  <span class="truncate">
                    {{ b.reference }}<template v-if="b.type"> · {{ b.type }}</template>
                  </span>
                </span>
                <span class="num shrink-0 text-mute">{{ formatMoney(b.price) }}</span>
              </li>
            </ul>

            <!-- A WON apartment can still be released (the sale fell through):
                 it returns to the market, its payments stay as history. -->
            <div
              v-if="u.state === 'won' && canClose()"
              class="mt-2 flex flex-wrap gap-1.5 border-t border-line pt-2"
            >
              <Button
:label="$t('deal.addBox')"
                icon="pi pi-car"
                size="small"
                severity="secondary"
                outlined
                @click="openAddBoxes(deal, u)"
              />
              <Button
:label="$t('deal.releaseFellThrough')"
                icon="pi pi-undo"
                size="small"
                severity="danger"
                outlined
                @click="releaseWon(deal, u)"
              />
            </div>

            <!-- Per-apartment actions while the deal is open. -->
            <div
              v-if="deal.state === 'open' && u.state === 'open'"
              class="mt-2 flex flex-wrap gap-1.5 border-t border-line pt-2"
            >
              <Button
                v-if="canClose()"
:label="$t('status.won')"
                icon="pi pi-trophy"
                size="small"
                severity="success"
                @click="winUnit(deal, u)"
              />
              <Button
                v-if="canClose()"
:label="$t('deal.release')"
                icon="pi pi-times"
                size="small"
                severity="danger"
                outlined
                @click="releaseUnit(deal, u)"
              />
              <Button
                v-if="canEditBoxes()"
:label="$t('deal.boxes')"
                icon="pi pi-car"
                size="small"
                severity="secondary"
                outlined
                @click="openBoxEditor(deal, u)"
              />
              <Button
                v-if="canRecord()"
:label="$t('deal.deposit')"
                icon="pi pi-wallet"
                size="small"
                severity="secondary"
                outlined
                @click="openDeposit(u)"
              />
            </div>
          </div>
        </div>

        <!-- Legacy boxes recorded before per-apartment grouping. -->
        <ul v-if="orphanBoxes(deal).length" class="mt-2 space-y-1 text-xs">
          <li
            v-for="b in orphanBoxes(deal)"
            :key="'ob' + b.item_id"
            class="flex items-center justify-between gap-2"
          >
            <span class="flex min-w-0 items-center gap-1.5 text-ink">
              <i class="pi pi-car shrink-0 text-mute" aria-hidden="true" />
              <span class="truncate">
                {{ b.reference }}<template v-if="b.type"> · {{ b.type }}</template>
              </span>
            </span>
            <span class="num shrink-0 text-mute">{{ formatMoney(b.price) }}</span>
          </li>
        </ul>

        <p v-if="deal.notes" class="mt-2 border-t border-line pt-2 text-xs text-mute">
          {{ deal.notes }}
        </p>

        <div
          v-if="deal.state === 'open' && canClose() && openUnits(deal).length > 1"
          class="mt-3"
        >
          <Button
:label="$t('deal.releaseEverything')"
            icon="pi pi-times"
            size="small"
            severity="danger"
            outlined
            @click="closeLostAll(deal)"
          />
        </div>
      </div>

      <p v-if="!openDeal && closedDeals.length" class="text-xs text-mute">
        {{ $t('deal.noOpenDeal') }}
      </p>
    </div>

    <!-- Win an apartment: agreed price + who deserves the credit. -->
    <BaseModal
      v-if="winFlow.open"
      :title="$t('deal.wonModalTitle', { ref: winFlow.unit.reference })"
      size="max-w-lg"
      @close="winFlow.open = false"
    >
      <p class="mb-3 text-sm text-mute">
        {{ $t('deal.wonModalBody') }}
      </p>
      <BaseInput
        v-model="winFlow.price"
        type="number"
        :label="$t('deal.agreedPriceLabel', { mil: MIL_LABEL })"
        required
        min="0"
        step="0.01"
        class="mb-3"
      />
      <BaseMultiSelect
        v-model="winFlow.sale"
:label="$t('deal.saleAgents')"
        :placeholder="$t('deal.saleAgentsPlaceholder')"
        :options="participantOptions"
        class="mb-3"
      />
      <BaseMultiSelect
        v-model="winFlow.insite"
:label="$t('deal.insiteAgents')"
        :placeholder="$t('deal.insiteAgentsPlaceholder')"
        :options="participantOptions"
        class="mb-3"
      />
      <BaseMultiSelect
        v-model="winFlow.other"
:label="$t('deal.others')"
        :placeholder="$t('deal.othersPlaceholder')"
        :options="staffOptions"
        class="mb-4"
      />
      <div class="flex gap-2">
        <Button
:label="$t('deal.markWon')"
          icon="pi pi-trophy"
          severity="success"
          :loading="store.saving"
          @click="confirmWin"
        />
        <Button :label="$t('common.cancel')" severity="secondary" outlined @click="winFlow.open = false" />
      </div>
    </BaseModal>

    <!-- Holding deposit — records a payment and Reserves the apartment. -->
    <BaseModal
      v-if="depositFlow.open"
      :title="$t('deal.depositModalTitle', { ref: depositFlow.unit.reference })"
      size="max-w-md"
      @close="depositFlow.open = false"
    >
      <p class="mb-3 text-sm text-mute">
        {{ $t('deal.depositModalBody') }}
      </p>
      <div class="grid gap-3 sm:grid-cols-2">
        <MoneyInput v-model="depositFlow.amount" :label="$t('deal.depositAmount')" required />
        <BaseInput v-model="depositFlow.paid_on" type="date" :label="$t('deal.paidOn')" required />
        <BaseSelect
          v-model="depositFlow.method_id"
:label="$t('deal.method')"
          required
          :options="methods.map((m) => ({ value: m.id, label: itemLabel(m) }))"
          class="sm:col-span-2"
        />

        <!-- Per-deal Reserved window: when the apartment goes back to the
             market (or to the next in line) if the sale doesn't finalize.
             Prefilled with the company default; empty also means default. -->
        <div v-if="depositArmsReservation" class="sm:col-span-2">
          <span class="mb-1.5 block text-sm font-medium text-ink">
            {{ $t('deal.backToMarketLabel') }}
          </span>
          <div class="flex gap-2">
            <BaseInput
              v-model="depositFlow.back_date"
              type="date"
              :min="todayInput()"
              class="flex-1"
            />
            <TimeField
              v-model="depositFlow.back_time"
              :aria-label="$t('common.time')"
              class="w-32"
            />
          </div>
          <p class="mt-1 text-xs text-mute">{{ $t('deal.backToMarketHint') }}</p>
        </div>
        <p v-else class="text-xs text-mute sm:col-span-2">
          {{
            $t('deal.alreadyReservedNote', {
              date: formatDateTime(depositFlow.unit.reserved_expires_at),
            })
          }}
        </p>
      </div>
      <div class="mt-4 flex gap-2">
        <Button
:label="$t('deal.recordDeposit')"
          icon="pi pi-check"
          :loading="depositSubmitting"
          :disabled="depositSubmitting"
          @click="submitDeposit"
        />
        <Button :label="$t('common.cancel')" severity="secondary" outlined @click="depositFlow.open = false" />
      </div>
    </BaseModal>

    <!-- Boxes editor — ONE apartment's boxes (linked + the unlinked pool). -->
    <BaseModal
      v-if="boxEditor.open"
      :title="$t('deal.boxesModalTitle', { ref: boxEditor.unit.reference })"
      size="max-w-lg"
      @close="boxEditor.open = false"
    >
      <p class="mb-3 text-sm text-mute">
        {{ $t('deal.boxesModalBody') }}
      </p>
      <UnitBoxPicker
        v-model="boxEditor.selected"
        :unit-id="boxEditor.unit.id"
        :location-id="boxEditor.unit.location_id"
        :current="boxEditor.current"
        class="mb-4"
      />
      <div class="flex gap-2">
        <Button :label="$t('deal.saveBoxes')" icon="pi pi-check" :loading="store.saving" @click="saveBoxes" />
        <Button :label="$t('common.cancel')" severity="secondary" outlined @click="boxEditor.open = false" />
      </div>
    </BaseModal>

    <!-- Sell extra boxes onto a WON apartment: sold + linked right away, the
         agreed price grows by the (negotiated) addition. -->
    <BaseModal
      v-if="addBoxFlow.open"
      :title="$t('deal.sellBoxModalTitle', { ref: addBoxFlow.unit.reference })"
      size="max-w-lg"
      @close="addBoxFlow.open = false"
    >
      <p class="mb-3 text-sm text-mute">
        {{ $t('deal.sellBoxModalBody') }}
      </p>
      <UnitBoxPicker
        v-model="addBoxFlow.selected"
        :unit-id="addBoxFlow.unit.id"
        :location-id="addBoxFlow.unit.location_id"
        class="mb-3"
      />
      <BaseInput
        v-model="addBoxFlow.price"
        type="number"
        :label="$t('deal.addedPriceLabel', { mil: MIL_LABEL })"
        min="0"
        step="0.01"
        class="mb-4"
      />
      <div class="flex gap-2">
        <Button
:label="$t('deal.sellBoxes')"
          icon="pi pi-check"
          :disabled="!addBoxFlow.selected.length || store.saving"
          @click="submitAddBoxes"
        />
        <Button :label="$t('common.cancel')" severity="secondary" outlined @click="addBoxFlow.open = false" />
      </div>
    </BaseModal>

    <!-- Releasing the LAST won apartment un-wins the project: resolve into a
         reopen (back to the pipeline) or an archive — payments stay as history. -->
    <BaseModal
      v-if="releaseWonFlow.open"
:title="$t('deal.releaseWonModalTitle')"
      size="max-w-md"
      @close="releaseWonFlow.open = false"
    >
      <p class="mb-3 text-sm text-mute">
        {{ $t('deal.releaseWonModalBody', { ref: releaseWonFlow.unit?.reference }) }}
      </p>
      <div class="space-y-2">
        <label
          class="flex cursor-pointer items-start gap-2 rounded-xl border p-3 text-sm"
          :class="
            releaseWonFlow.resolution === 'reopen'
              ? 'border-primary bg-highlight'
              : 'border-line hover:border-primary-300'
          "
        >
          <input
            v-model="releaseWonFlow.resolution"
            type="radio"
            value="reopen"
            class="mt-0.5 accent-primary"
          />
          <span>
            <span class="font-medium text-ink">{{ $t('deal.reopenPipeline') }}</span>
            <span class="mt-0.5 block text-xs text-mute">
              {{ $t('deal.reopenReleasedHint') }}
            </span>
          </span>
        </label>
        <label
          class="flex cursor-pointer items-start gap-2 rounded-xl border p-3 text-sm"
          :class="
            releaseWonFlow.resolution === 'archive'
              ? 'border-primary bg-highlight'
              : 'border-line hover:border-primary-300'
          "
        >
          <input
            v-model="releaseWonFlow.resolution"
            type="radio"
            value="archive"
            class="mt-0.5 accent-primary"
          />
          <span>
            <span class="font-medium text-ink">{{ $t('deal.archiveProject') }}</span>
            <span class="mt-0.5 block text-xs text-mute">
              {{ $t('deal.archiveHint') }}
            </span>
          </span>
        </label>
      </div>
      <BaseTextarea
        v-if="releaseWonFlow.resolution === 'archive'"
        v-model="releaseWonFlow.note"
:label="$t('common.note')"
        required
        :rows="2"
        class="mt-3"
      />
      <div class="mt-4 flex gap-2">
        <Button
:label="$t('deal.release')"
          icon="pi pi-undo"
          severity="danger"
          :disabled="!releaseWonReady || store.saving"
          @click="confirmReleaseWonResolved"
        />
        <Button :label="$t('common.cancel')" severity="secondary" outlined @click="releaseWonFlow.open = false" />
      </div>
    </BaseModal>

    <!-- The deal ends lost: resolve into a reopen or an archive -->
    <BaseModal
      v-if="lostFlow.open"
:title="$t('deal.lostModalTitle')"
      size="max-w-md"
      @close="lostFlow.open = false"
    >
      <p class="mb-3 text-sm text-mute">
        {{
          lostFlow.unit
            ? $t('deal.lostModalBodyUnit', { ref: lostFlow.unit.reference })
            : $t('deal.lostModalBodyAll')
        }}
        {{ $t('deal.howContinue') }}
      </p>
      <div class="space-y-2">
        <label
          class="flex cursor-pointer items-start gap-2 rounded-xl border p-3 text-sm"
          :class="
            lostFlow.resolution === 'reopen'
              ? 'border-primary bg-highlight'
              : 'border-line hover:border-primary-300'
          "
        >
          <input
            v-model="lostFlow.resolution"
            type="radio"
            value="reopen"
            class="mt-0.5 accent-primary"
          />
          <span>
            <span class="font-medium text-ink">{{ $t('deal.reopenPipeline') }}</span>
            <span class="mt-0.5 block text-xs text-mute">
              {{ $t('deal.reopenLostHint') }}
            </span>
          </span>
        </label>
        <label
          class="flex cursor-pointer items-start gap-2 rounded-xl border p-3 text-sm"
          :class="
            lostFlow.resolution === 'archive'
              ? 'border-primary bg-highlight'
              : 'border-line hover:border-primary-300'
          "
        >
          <input
            v-model="lostFlow.resolution"
            type="radio"
            value="archive"
            class="mt-0.5 accent-primary"
          />
          <span>
            <span class="font-medium text-ink">{{ $t('deal.archiveProject') }}</span>
            <span class="mt-0.5 block text-xs text-mute">
              {{ $t('deal.archiveHint') }}
            </span>
          </span>
        </label>
      </div>
      <BaseTextarea
        v-if="lostFlow.resolution === 'archive'"
        v-model="lostFlow.note"
:label="$t('common.note')"
        required
        :rows="2"
        class="mt-3"
      />
      <div class="mt-4 flex gap-2">
        <Button
:label="$t('deal.markLost')"
          icon="pi pi-check"
          severity="danger"
          :disabled="!lostReady || store.saving"
          @click="confirmLost"
        />
        <Button :label="$t('common.cancel')" severity="secondary" outlined @click="lostFlow.open = false" />
      </div>
    </BaseModal>
  </SectionCard>
</template>
