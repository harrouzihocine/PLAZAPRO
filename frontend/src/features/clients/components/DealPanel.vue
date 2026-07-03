<script setup>
import Swal from 'sweetalert2'
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import BaseModal from '@/components/base/BaseModal.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import StatusTag from '@/components/ui/StatusTag.vue'
import { confirmAction, toastError } from '@/composables/useConfirm'
import { useClientsStore } from '@/features/clients/clientsStore'
import { boxesApi } from '@/features/inventory/api'
import { useAuthStore } from '@/features/settings/store'
import { formatMoney } from '@/features/payments/money'

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
  await store.closeDeal(props.clientId, props.projectId, deal.id, {
    outcome: 'won',
    total_price: value,
  })
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
  <SectionCard v-if="deals.length" title="Deal" icon="pi pi-briefcase">
    <div class="space-y-3">
      <div
        v-for="deal in deals"
        :key="deal.id"
        class="rounded-lg border p-3"
        :class="
          deal.state === 'reserved'
            ? 'border-primary-300 bg-primary-50/50 dark:border-primary-500/30 dark:bg-primary-500/5'
            : 'border-line'
        "
      >
        <div class="mb-2 flex items-center justify-between gap-2">
          <StatusTag :value="deal.state" />
          <span v-if="deal.total_price" class="num text-sm font-semibold text-ink">
            {{ formatMoney(deal.total_price) }}
          </span>
          <span v-else class="num text-xs text-mute">
            suggested {{ suggestedTotal(deal) ? formatMoney(suggestedTotal(deal)) : '—' }}
          </span>
        </div>

        <!-- The reserved properties — the full card, not just the code. -->
        <ul class="divide-y divide-line text-sm">
          <li
            v-for="u in deal.units"
            :key="'u' + u.id"
            class="flex items-center justify-between gap-2 py-1.5"
          >
            <span class="flex min-w-0 items-center gap-2">
              <i class="pi pi-home shrink-0 text-mute" aria-hidden="true" />
              <span class="truncate text-ink">{{ unitLine(u) }}</span>
            </span>
            <span class="num shrink-0 text-xs text-mute">{{ formatMoney(u.price) }}</span>
          </li>
          <li
            v-for="b in deal.boxes"
            :key="'b' + b.id"
            class="flex items-center justify-between gap-2 py-1.5"
          >
            <span class="flex min-w-0 items-center gap-2">
              <i class="pi pi-car shrink-0 text-mute" aria-hidden="true" />
              <span class="truncate text-ink">
                {{ b.reference }}<template v-if="b.type"> · {{ b.type }}</template>
              </span>
            </span>
            <span class="num shrink-0 text-xs text-mute">{{ formatMoney(b.price) }}</span>
          </li>
        </ul>
        <p v-if="deal.state === 'reserved' && !deal.boxes?.length" class="mt-1 text-xs text-mute">
          No boxes reserved on this deal.
        </p>
        <p v-if="deal.notes" class="mt-2 border-t border-line pt-2 text-xs text-mute">
          {{ deal.notes }}
        </p>

        <div v-if="deal.state === 'reserved'" class="mt-3 flex flex-wrap gap-2">
          <Button
            v-if="canClose()"
            label="Close won"
            icon="pi pi-trophy"
            size="small"
            severity="success"
            @click="closeWon(deal)"
          />
          <Button
            v-if="canClose()"
            label="Close lost"
            icon="pi pi-times"
            size="small"
            severity="danger"
            outlined
            @click="closeLost(deal)"
          />
          <Button
            v-if="canEditBoxes()"
            label="Boxes…"
            icon="pi pi-car"
            size="small"
            severity="secondary"
            outlined
            @click="openBoxEditor(deal)"
          />
        </div>
      </div>

      <p v-if="!openDeal && closedDeals.length" class="text-xs text-mute">
        No open deal — a new one can be created from a visit log.
      </p>
    </div>

    <!-- Reserved-boxes editor -->
    <BaseModal
      v-if="boxEditor.open"
      title="Boxes on this deal"
      size="max-w-lg"
      @close="boxEditor.open = false"
    >
      <p class="mb-3 text-sm text-mute">
        Tap to reserve / release boxes alongside the apartment. Only available (not taken) boxes are
        offered.
      </p>
      <div class="mb-4 flex flex-wrap gap-2">
        <button
          v-for="b in boxEditor.candidates"
          :key="b.id"
          type="button"
          class="rounded-lg border px-2.5 py-1.5 text-xs transition-colors"
          :class="
            boxEditor.selected.includes(b.id)
              ? 'border-primary bg-highlight font-medium text-ink'
              : 'border-line text-mute hover:border-primary hover:text-ink'
          "
          @click="toggleBox(b.id)"
        >
          <i class="pi pi-car text-[10px]" aria-hidden="true" />
          {{ b.reference }}<template v-if="b.price"> · {{ formatMoney(b.price) }}</template>
        </button>
        <p v-if="!boxEditor.candidates.length" class="text-sm text-mute">
          No boxes exist in this project.
        </p>
      </div>
      <div class="flex gap-2">
        <Button label="Save boxes" icon="pi pi-check" :loading="store.saving" @click="saveBoxes" />
        <Button label="Cancel" severity="secondary" outlined @click="boxEditor.open = false" />
      </div>
    </BaseModal>
  </SectionCard>
</template>
