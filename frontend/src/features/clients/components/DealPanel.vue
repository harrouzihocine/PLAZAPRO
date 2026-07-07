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
import SectionCard from '@/components/ui/SectionCard.vue'
import StatusTag from '@/components/ui/StatusTag.vue'
import { dealsApi, staffApi } from '@/features/clients/api'
import { useClientsStore } from '@/features/clients/clientsStore'
import SaleStatusBadge from '@/features/inventory/components/SaleStatusBadge.vue'
import UnitBoxPicker from '@/features/inventory/components/UnitBoxPicker.vue'
import { useAuthStore } from '@/features/settings/store'
import { useDynamicList } from '@/composables/useDynamicList'
import { versementsApi } from '@/features/payments/api'
import { useNetworkStore } from '@/features/offline/networkStore'
import { toastError, toastSuccess } from '@/composables/useConfirm'
import { formatDate, todayInput } from '@/utils/format'
import { dzdToMil, formatMoney, milToDzd, MIL_LABEL } from '@/features/payments/money'

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
})
// Guards double-submit: the deposit records a versement directly (not via a
// store action), so store.saving never covers it — a double-click would book
// two deposits without this.
const depositSubmitting = ref(false)

function openDeposit(unit) {
  depositFlow.value = {
    open: true,
    unit,
    amount: '',
    paid_on: todayInput(),
    method_id: '',
  }
}

async function submitDeposit() {
  // Financial writes never queue offline — the reservation lock must be live.
  if (!useNetworkStore().requireOnline("You're offline — recording a deposit needs a connection.")) {
    return
  }
  const f = depositFlow.value
  if (!f.amount || !f.method_id) {
    toastError('Enter a deposit amount and a payment method.')
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
    })
    depositFlow.value.open = false
    toastSuccess(`Deposit recorded — ${f.unit.reference} is reserved.`)
    // Reload the deals so the apartment card shows the deposit + Reserved state.
    await store.loadDeals(props.projectId)
    emit('changed')
  } catch (e) {
    toastError(e.response?.data?.message ?? 'Could not record the deposit.')
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
    toastError('Enter a valid agreed price.')
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
    title: `Release ${unit.reference}?`,
    text: 'The apartment and its boxes return to available inventory. The project continues on what is still open or won.',
    showCancelButton: true,
    confirmButtonText: 'Release',
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
    title: 'Release everything on this deal?',
    text: 'Every remaining apartment returns to available inventory. The project continues on its other deal(s).',
    showCancelButton: true,
    confirmButtonText: 'Release all',
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
    title: `Release ${unit.reference}?`,
    text: 'The sale fell through — the apartment and its boxes return to available inventory. Its recorded payments stay as history (refund them from the payments panel).',
    showCancelButton: true,
    confirmButtonText: 'Release',
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
  <SectionCard v-if="deals.length" title="Deal" icon="pi pi-briefcase">
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
              Deposit paid
              <span class="num font-semibold text-ink">{{ formatMoney(u.collected) }}</span>
              <span v-if="u.sale_status === 'reserved' && u.reserved_expires_at">
                · reserved until {{ formatDate(u.reserved_expires_at) }}
              </span>
            </p>

            <!-- Who was credited for the sale (won apartment). -->
            <div
              v-if="u.state === 'won' && u.credited"
              class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-mute"
            >
              <span v-if="u.credited.sale.length">
                <i class="pi pi-megaphone text-[10px] text-primary-500" aria-hidden="true" />
                Sale: <span class="text-ink">{{ u.credited.sale.join(', ') }}</span>
              </span>
              <span v-if="u.credited.insite.length">
                <i class="pi pi-map-marker text-[10px] text-primary-500" aria-hidden="true" />
                In-site: <span class="text-ink">{{ u.credited.insite.join(', ') }}</span>
              </span>
              <span v-if="u.credited.other.length">
                <i class="pi pi-users text-[10px] text-primary-500" aria-hidden="true" />
                Others: <span class="text-ink">{{ u.credited.other.join(', ') }}</span>
              </span>
            </div>

            <!-- The apartment's boxes, right under it. -->
            <ul v-if="boxesOf(deal, u).length" class="mt-1.5 space-y-1 border-t border-line pt-1.5">
              <li
                v-for="b in boxesOf(deal, u)"
                :key="'b' + b.item_id"
                class="flex items-center justify-between gap-2 pl-6 text-xs"
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
                label="Add box…"
                icon="pi pi-car"
                size="small"
                severity="secondary"
                outlined
                @click="openAddBoxes(deal, u)"
              />
              <Button
                label="Release (sale fell through)"
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
                label="Won"
                icon="pi pi-trophy"
                size="small"
                severity="success"
                @click="winUnit(deal, u)"
              />
              <Button
                v-if="canClose()"
                label="Release"
                icon="pi pi-times"
                size="small"
                severity="danger"
                outlined
                @click="releaseUnit(deal, u)"
              />
              <Button
                v-if="canEditBoxes()"
                label="Boxes…"
                icon="pi pi-car"
                size="small"
                severity="secondary"
                outlined
                @click="openBoxEditor(deal, u)"
              />
              <Button
                v-if="canRecord()"
                label="Deposit…"
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
            label="Release everything (lost)"
            icon="pi pi-times"
            size="small"
            severity="danger"
            outlined
            @click="closeLostAll(deal)"
          />
        </div>
      </div>

      <p v-if="!openDeal && closedDeals.length" class="text-xs text-mute">
        No open deal — a new one can be created from the logs (call / visit).
      </p>
    </div>

    <!-- Win an apartment: agreed price + who deserves the credit. -->
    <BaseModal
      v-if="winFlow.open"
      :title="`${winFlow.unit.reference} — won 🎉`"
      size="max-w-lg"
      @close="winFlow.open = false"
    >
      <p class="mb-3 text-sm text-mute">
        The agreed price for this apartment (its boxes included), and who deserves the credit — the
        sale &amp; in-site pickers list everyone who worked this project; pick who earned it.
      </p>
      <BaseInput
        v-model="winFlow.price"
        type="number"
        :label="`Agreed price in ${MIL_LABEL} DZD (boxes included)`"
        required
        min="0"
        step="0.01"
        class="mb-3"
      />
      <BaseMultiSelect
        v-model="winFlow.sale"
        label="Sale agent(s) — the marketing"
        placeholder="Who marketed / sold it"
        :options="participantOptions"
        class="mb-3"
      />
      <BaseMultiSelect
        v-model="winFlow.insite"
        label="In-site agent(s) — the site visits"
        placeholder="Who ran the site visits"
        :options="participantOptions"
        class="mb-3"
      />
      <BaseMultiSelect
        v-model="winFlow.other"
        label="Others"
        placeholder="Anyone else to credit"
        :options="staffOptions"
        class="mb-4"
      />
      <div class="flex gap-2">
        <Button
          label="Mark won"
          icon="pi pi-trophy"
          severity="success"
          :loading="store.saving"
          @click="confirmWin"
        />
        <Button label="Cancel" severity="secondary" outlined @click="winFlow.open = false" />
      </div>
    </BaseModal>

    <!-- Holding deposit — records a payment and Reserves the apartment. -->
    <BaseModal
      v-if="depositFlow.open"
      :title="`Holding deposit — ${depositFlow.unit.reference}`"
      size="max-w-md"
      @close="depositFlow.open = false"
    >
      <p class="mb-3 text-sm text-mute">
        Recording a deposit makes this apartment <strong>Reserved</strong> — off the market for
        everyone else (others may still queue as backups) until it is sold or the hold lapses.
      </p>
      <div class="grid gap-3 sm:grid-cols-2">
        <MoneyInput v-model="depositFlow.amount" label="Deposit amount" required />
        <BaseInput v-model="depositFlow.paid_on" type="date" label="Paid on" required />
        <BaseSelect
          v-model="depositFlow.method_id"
          label="Method"
          required
          placeholder="Select…"
          :options="methods.map((m) => ({ value: m.id, label: m.label }))"
          class="sm:col-span-2"
        />
      </div>
      <div class="mt-4 flex gap-2">
        <Button
          label="Record deposit"
          icon="pi pi-check"
          :loading="depositSubmitting"
          :disabled="depositSubmitting"
          @click="submitDeposit"
        />
        <Button label="Cancel" severity="secondary" outlined @click="depositFlow.open = false" />
      </div>
    </BaseModal>

    <!-- Boxes editor — ONE apartment's boxes (linked + the unlinked pool). -->
    <BaseModal
      v-if="boxEditor.open"
      :title="`Boxes with ${boxEditor.unit.reference}`"
      size="max-w-lg"
      @close="boxEditor.open = false"
    >
      <p class="mb-3 text-sm text-mute">
        Tap to add / release boxes with this apartment. Only its linked boxes and the project's
        unlinked ones are offered — picking an unlinked box links it here.
      </p>
      <UnitBoxPicker
        v-model="boxEditor.selected"
        :unit-id="boxEditor.unit.id"
        :location-id="boxEditor.unit.location_id"
        :current="boxEditor.current"
        class="mb-4"
      />
      <div class="flex gap-2">
        <Button label="Save boxes" icon="pi pi-check" :loading="store.saving" @click="saveBoxes" />
        <Button label="Cancel" severity="secondary" outlined @click="boxEditor.open = false" />
      </div>
    </BaseModal>

    <!-- Sell extra boxes onto a WON apartment: sold + linked right away, the
         agreed price grows by the (negotiated) addition. -->
    <BaseModal
      v-if="addBoxFlow.open"
      :title="`Sell a box with ${addBoxFlow.unit.reference}`"
      size="max-w-lg"
      @close="addBoxFlow.open = false"
    >
      <p class="mb-3 text-sm text-mute">
        The picked boxes are sold and linked to this apartment right away, and its agreed price
        grows by the addition. Re-adjust the instalment plan afterwards if one exists.
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
        :label="`Added price in ${MIL_LABEL} DZD (defaults to the boxes' list prices)`"
        min="0"
        step="0.01"
        class="mb-4"
      />
      <div class="flex gap-2">
        <Button
          label="Sell boxes"
          icon="pi pi-check"
          :disabled="!addBoxFlow.selected.length || store.saving"
          @click="submitAddBoxes"
        />
        <Button label="Cancel" severity="secondary" outlined @click="addBoxFlow.open = false" />
      </div>
    </BaseModal>

    <!-- Releasing the LAST won apartment un-wins the project: resolve into a
         reopen (back to the pipeline) or an archive — payments stay as history. -->
    <BaseModal
      v-if="releaseWonFlow.open"
      title="Won apartment released — what happens next?"
      size="max-w-md"
      @close="releaseWonFlow.open = false"
    >
      <p class="mb-3 text-sm text-mute">
        Releasing {{ releaseWonFlow.unit?.reference }} un-wins the project — nothing sold remains on
        it. Its recorded payments stay as history (refund them from the payments panel). How should
        this project continue?
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
            <span class="font-medium text-ink">Reopen the pipeline</span>
            <span class="mt-0.5 block text-xs text-mute">
              Step back so the logs resume and a fresh deal can start. The released win is kept as
              history.
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
            <span class="font-medium text-ink">Archive the project</span>
            <span class="mt-0.5 block text-xs text-mute">
              Close it. Recorded payments are kept as history — add a note (e.g. refund handling).
            </span>
          </span>
        </label>
      </div>
      <BaseTextarea
        v-if="releaseWonFlow.resolution === 'archive'"
        v-model="releaseWonFlow.note"
        label="Note"
        required
        :rows="2"
        class="mt-3"
      />
      <div class="mt-4 flex gap-2">
        <Button
          label="Release"
          icon="pi pi-undo"
          severity="danger"
          :disabled="!releaseWonReady || store.saving"
          @click="confirmReleaseWonResolved"
        />
        <Button label="Cancel" severity="secondary" outlined @click="releaseWonFlow.open = false" />
      </div>
    </BaseModal>

    <!-- The deal ends lost: resolve into a reopen or an archive -->
    <BaseModal
      v-if="lostFlow.open"
      title="Deal lost — what happens next?"
      size="max-w-md"
      @close="lostFlow.open = false"
    >
      <p class="mb-3 text-sm text-mute">
        {{
          lostFlow.unit
            ? `Releasing ${lostFlow.unit.reference} ends the deal — nothing was won on it.`
            : 'Every remaining apartment is released back to available inventory.'
        }}
        How should this project continue?
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
            <span class="font-medium text-ink">Reopen the pipeline</span>
            <span class="mt-0.5 block text-xs text-mute">
              Step back to negotiating so the logs reopen and a fresh deal can start. The lost deal
              is kept as history.
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
            <span class="font-medium text-ink">Archive the project</span>
            <span class="mt-0.5 block text-xs text-mute">
              Close it. Any recorded payments are kept as history — add a note (e.g. refund
              handling).
            </span>
          </span>
        </label>
      </div>
      <BaseTextarea
        v-if="lostFlow.resolution === 'archive'"
        v-model="lostFlow.note"
        label="Note"
        required
        :rows="2"
        class="mt-3"
      />
      <div class="mt-4 flex gap-2">
        <Button
          label="Mark lost"
          icon="pi pi-check"
          severity="danger"
          :disabled="!lostReady || store.saving"
          @click="confirmLost"
        />
        <Button label="Cancel" severity="secondary" outlined @click="lostFlow.open = false" />
      </div>
    </BaseModal>
  </SectionCard>
</template>
