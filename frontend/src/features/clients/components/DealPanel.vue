<script setup>
import Swal from 'sweetalert2'
import { computed, onMounted, ref } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseModal from '@/components/base/BaseModal.vue'
import { confirmAction, toastError } from '@/composables/useConfirm'
import { useClientsStore } from '@/features/clients/clientsStore'
import { boxesApi } from '@/features/inventory/api'
import { useAuthStore } from '@/features/settings/store'

// THE deal on a project: its reserved properties (full card each), the boxes
// reserved alongside (editable while open), and the close actions. Closing won
// pre-fills the agreed price from the reserved property prices; closing lost
// releases everything back to inventory.
const props = defineProps({
  clientId: { type: [String, Number], required: true },
  projectId: { type: [String, Number], required: true },
})
const store = useClientsStore()
const auth = useAuthStore()

const canClose = () => auth.can('clients.manage')
const canEditBoxes = () => auth.can('visits.conduct')

const deals = computed(() => store.deals[props.projectId] ?? [])
const openDeal = computed(() => deals.value.find((d) => d.state === 'reserved'))
const closedDeals = computed(() => deals.value.filter((d) => d.state !== 'reserved'))

const stateClass = {
  reserved: 'bg-primary/15 text-primary',
  won: 'bg-success/15 text-success',
  lost: 'bg-danger/15 text-danger',
}

const unitLine = (u) =>
  [u.reference, u.type, u.floor, u.area_sqm ? `${u.area_sqm} m²` : null, u.location]
    .filter(Boolean)
    .join(' · ')

// Suggested agreed price = every reserved property's list price.
const suggestedTotal = (deal) =>
  [...(deal.units ?? []), ...(deal.boxes ?? [])].reduce((sum, p) => sum + Number(p.price ?? 0), 0)

onMounted(() => store.loadDeals(props.projectId))

async function closeWon(deal) {
  const suggested = suggestedTotal(deal)
  const { value, isConfirmed } = await Swal.fire({
    title: 'Close deal — won 🎉',
    text: 'Agreed total price (pre-filled from the reserved apartment/local + boxes).',
    input: 'number',
    inputValue: suggested || undefined,
    inputAttributes: { min: '0', step: '0.01' },
    showCancelButton: true,
    confirmButtonText: 'Mark won',
    inputValidator: (v) => (!v || Number(v) < 0 ? 'Enter a valid price.' : undefined),
    customClass: { confirmButton: 'plaza-swal-confirm', cancelButton: 'plaza-swal-cancel' },
  })
  if (!isConfirmed) return
  await store.closeDeal(props.clientId, props.projectId, deal.id, { outcome: 'won', total_price: value })
}

async function closeLost(deal) {
  if (
    await confirmAction({
      title: 'Close deal — lost?',
      text: 'The reserved properties are released back to available inventory.',
      confirmText: 'Mark lost',
      danger: true,
    })
  ) {
    await store.closeDeal(props.clientId, props.projectId, deal.id, { outcome: 'lost' })
  }
}

// --- Reserved boxes editor (open deal only) ---
const boxEditor = ref({ open: false, deal: null, selected: [], candidates: [] })

async function openBoxEditor(deal) {
  const locationIds = [...new Set((deal.units ?? []).map((u) => u.location_id).filter(Boolean))]
  try {
    const lists = await Promise.all(
      locationIds.map((id) => boxesApi.list({ location_id: id, sale_status: 'available' })),
    )
    const current = (deal.boxes ?? []).map((b) => ({ ...b }))
    boxEditor.value = {
      open: true,
      deal,
      selected: current.map((b) => b.id),
      // Current boxes + the still-available ones, deduped.
      candidates: [...current, ...lists.flat().filter((b) => !current.some((c) => c.id === b.id))],
    }
  } catch {
    toastError('Could not load the available boxes.')
  }
}

function toggleBox(id) {
  const i = boxEditor.value.selected.indexOf(id)
  if (i === -1) boxEditor.value.selected.push(id)
  else boxEditor.value.selected.splice(i, 1)
}

async function saveBoxes() {
  try {
    await store.syncDealBoxes(props.projectId, boxEditor.value.deal.id, boxEditor.value.selected)
    boxEditor.value.open = false
  } catch {
    /* toast raised by the store */
  }
}
</script>

<template>
  <div v-if="deals.length" class="rounded-token border border-border p-3">
    <h3 class="mb-2 text-sm font-semibold uppercase opacity-60">Deal</h3>

    <div v-for="deal in deals" :key="deal.id" class="mb-2 rounded-token border border-border p-2 last:mb-0">
      <div class="mb-1 flex items-center justify-between gap-2">
        <span class="rounded-token px-2 py-0.5 text-xs font-medium" :class="stateClass[deal.state]">
          {{ deal.state }}
        </span>
        <span v-if="deal.total_price" class="text-sm font-medium">{{ deal.total_price }}</span>
        <span v-else class="text-xs opacity-60">suggested {{ suggestedTotal(deal) || '—' }}</span>
      </div>

      <!-- The reserved properties — the full card, not just the code. -->
      <ul class="space-y-1 text-sm">
        <li v-for="u in deal.units" :key="'u' + u.id" class="flex items-center justify-between gap-2">
          <span>🏠 {{ unitLine(u) }}</span>
          <span class="text-xs opacity-70">{{ u.price }}</span>
        </li>
        <li v-for="b in deal.boxes" :key="'b' + b.id" class="flex items-center justify-between gap-2">
          <span>🅿 {{ b.reference }}<template v-if="b.type"> · {{ b.type }}</template></span>
          <span class="text-xs opacity-70">{{ b.price }}</span>
        </li>
      </ul>
      <p v-if="deal.state === 'reserved' && !deal.boxes?.length" class="mt-1 text-xs opacity-60">
        No boxes reserved on this deal.
      </p>
      <p v-if="deal.notes" class="mt-1 border-t border-border pt-1 text-xs opacity-70">{{ deal.notes }}</p>

      <div v-if="deal.state === 'reserved'" class="mt-2 flex flex-wrap gap-1.5">
        <BaseButton v-if="canClose()" variant="ghost" class="!px-2 !py-1 text-xs text-success" @click="closeWon(deal)">
          ✓ Close won
        </BaseButton>
        <BaseButton v-if="canClose()" variant="ghost" class="!px-2 !py-1 text-xs text-danger" @click="closeLost(deal)">
          ✕ Close lost
        </BaseButton>
        <BaseButton v-if="canEditBoxes()" variant="ghost" class="!px-2 !py-1 text-xs" @click="openBoxEditor(deal)">
          🅿 Boxes…
        </BaseButton>
      </div>
    </div>

    <p v-if="!openDeal && closedDeals.length" class="text-xs opacity-60">
      No open deal — a new one can be created from a visit log.
    </p>

    <!-- Reserved-boxes editor -->
    <BaseModal v-if="boxEditor.open" title="Boxes on this deal" size="max-w-lg" @close="boxEditor.open = false">
      <p class="mb-2 text-sm opacity-70">
        Tap to reserve / release boxes alongside the apartment. Only available (not taken) boxes are offered.
      </p>
      <div class="mb-3 flex flex-wrap gap-1.5">
        <button
          v-for="b in boxEditor.candidates"
          :key="b.id"
          type="button"
          class="rounded-token border px-2 py-1 text-xs transition-colors"
          :class="boxEditor.selected.includes(b.id) ? 'border-primary bg-primary/15 text-ink' : 'border-border bg-bg opacity-80 hover:border-primary'"
          @click="toggleBox(b.id)"
        >
          🅿 {{ b.reference }}<template v-if="b.price"> · {{ b.price }}</template>
        </button>
        <p v-if="!boxEditor.candidates.length" class="text-sm opacity-60">No boxes exist in this project.</p>
      </div>
      <div class="flex gap-2">
        <BaseButton :disabled="store.saving" @click="saveBoxes">Save boxes</BaseButton>
        <BaseButton variant="ghost" @click="boxEditor.open = false">Cancel</BaseButton>
      </div>
    </BaseModal>
  </div>
</template>
