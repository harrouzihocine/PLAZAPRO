<script setup>
import Swal from 'sweetalert2'
import { computed, ref } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseModal from '@/components/base/BaseModal.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'
import DealPanel from '@/features/clients/components/DealPanel.vue'
import DesireFields from '@/features/clients/components/DesireFields.vue'
import ShortlistPanel from '@/features/clients/components/ShortlistPanel.vue'
import ProjectUnitsPicker from '@/features/inventory/components/ProjectUnitsPicker.vue'
import PaymentsPanel from '@/features/payments/components/PaymentsPanel.vue'
import TimelinePanel from '@/features/pipeline/components/TimelinePanel.vue'
import { useClientsStore } from '@/features/clients/clientsStore'
import { useAuthStore } from '@/features/settings/store'
import { useDynamicList } from '@/composables/useDynamicList'
import { confirmAction, toastError } from '@/composables/useConfirm'

// ONE project engagement on the client file: a compact card (step badge + what
// the client is buying) that expands into the project's whole story — its own
// timeline (logs), shortlist, deal and payments — with the lifecycle actions in
// modals (shift to desire, archive, direct deal).
const props = defineProps({
  clientId: { type: [String, Number], required: true },
  project: { type: Object, required: true },
  expanded: { type: Boolean, default: false },
})
const emit = defineEmits(['toggle', 'changed'])

const store = useClientsStore()
const auth = useAuthStore()
const { items: archiveReasons } = useDynamicList('archive_reasons')

const canManage = () => auth.can('clients.manage')
const canDirectDeal = () => auth.can('deals.direct')
const canViewPayments = () => auth.can('versements.view')

// Where the project stands — one badge, derived server-side.
const stepBadge = {
  new: { label: 'New', cls: 'bg-border text-ink' },
  qualifying: { label: 'Qualifying', cls: 'bg-border text-ink' },
  office_visit: { label: '🏢 Office visit', cls: 'bg-warning/15 text-warning' },
  in_site_visit: { label: '🏘 In-site visit', cls: 'bg-warning/15 text-warning' },
  deal: { label: '💼 Deal — reserved', cls: 'bg-primary/15 text-primary' },
  won: { label: '✓ Won', cls: 'bg-success/15 text-success' },
  lost: { label: 'Lost', cls: 'bg-danger/15 text-danger' },
  desire: { label: '📋 On desire list', cls: 'bg-warning/15 text-warning' },
  archived: { label: 'Archived', cls: 'bg-border text-ink' },
  removed: { label: 'Removed', cls: 'bg-danger/15 text-danger' },
}
const badge = computed(() => stepBadge[props.project.step] ?? stepBadge.new)
const isClosed = computed(() => props.project.status !== 'active')

const unitLine = (u) =>
  [u.reference, u.type, u.floor, u.area_sqm ? `${u.area_sqm} m²` : null, u.price]
    .filter(Boolean)
    .join(' · ')

// --- Shift to desire (modal): archive the project + re-capture the wants ---
const shiftOpen = ref(false)
const shiftForm = ref({
  wilaya_id: '', commune_id: '', type_id: '',
  budget_min: '', budget_max: '', floor_pref: '', notes: '',
})

function openShift() {
  const d = store.desire
  shiftForm.value = {
    wilaya_id: d?.wilaya_id ?? '',
    commune_id: d?.commune_id ?? '',
    type_id: d?.type_id ?? '',
    budget_min: d?.budget_min ?? '',
    budget_max: d?.budget_max ?? '',
    floor_pref: d?.floor_pref ?? '',
    notes: d?.notes ?? '',
  }
  shiftOpen.value = true
}

async function submitShift() {
  try {
    await store.shiftProjectToDesire(props.clientId, props.project.id, {
      wilaya_id: shiftForm.value.wilaya_id || null,
      commune_id: shiftForm.value.commune_id || null,
      type_id: shiftForm.value.type_id || null,
      budget_min: shiftForm.value.budget_min === '' ? null : Number(shiftForm.value.budget_min),
      budget_max: shiftForm.value.budget_max === '' ? null : Number(shiftForm.value.budget_max),
      floor_pref: (shiftForm.value.floor_pref ?? '').trim() || null,
      notes: (shiftForm.value.notes ?? '').trim() || null,
    })
    shiftOpen.value = false
    emit('changed')
  } catch {
    /* toast raised by the store */
  }
}

// --- Archive (reason required; blocked once payments exist) ---
async function archiveProject() {
  const { value, isConfirmed } = await Swal.fire({
    title: 'Close this project?',
    text: 'Pick a reason. Nothing more can be logged on it until it is reactivated.',
    input: 'select',
    inputOptions: Object.fromEntries(archiveReasons.value.map((r) => [r.id, r.label])),
    inputPlaceholder: 'Select a reason…',
    showCancelButton: true,
    confirmButtonText: 'Archive',
    inputValidator: (v) => (!v ? 'A reason is required.' : undefined),
    customClass: { confirmButton: 'plaza-swal-confirm', cancelButton: 'plaza-swal-cancel' },
  })
  if (isConfirmed && value) {
    await store.archiveProject(props.clientId, props.project.id, { archive_reason_id: Number(value) })
    emit('changed')
  }
}

async function reactivateProject() {
  await store.reactivateProject(props.clientId, props.project.id)
  emit('changed')
}

async function removeProject() {
  if (
    await confirmAction({
      title: 'Remove this project?',
      text: 'This removes the project and everything inside it. The records are kept but marked cancelled.',
      confirmText: 'Remove',
      danger: true,
    })
  ) {
    await store.cancelProject(props.clientId, props.project.id)
    emit('changed')
  }
}

// --- Direct deal (permission-gated): pick properties + boxes, reserve now ---
const directDealOpen = ref(false)
const directDealUnits = ref([])
const directDealNotes = ref('')

async function submitDirectDeal() {
  const units = directDealUnits.value
    .filter((p) => p.shortlistable_type === 'unit')
    .map((p) => ({ unit_id: p.shortlistable_id, box_count: p.box_count ?? 0 }))
  if (!units.length) {
    toastError('Pick at least one apartment / local for the deal.')
    return
  }
  try {
    await store.createDeal(props.clientId, props.project.id, {
      units,
      notes: directDealNotes.value.trim() || null,
    })
    directDealOpen.value = false
    directDealUnits.value = []
    directDealNotes.value = ''
    emit('changed')
  } catch {
    /* toast raised by the store */
  }
}
</script>

<template>
  <div class="rounded-token border" :class="isClosed ? 'border-dashed border-border opacity-90' : 'border-border'">
    <!-- Compact card row -->
    <button type="button" class="flex w-full items-center gap-2 p-2 text-left" @click="emit('toggle')">
      <span class="rounded-token px-2 py-0.5 text-xs font-medium" :class="badge.cls">{{ badge.label }}</span>
      <span class="min-w-0 flex-1 truncate text-sm">
        <template v-if="project.unit">🏠 {{ unitLine(project.unit) }}</template>
        <template v-else-if="project.location">{{ project.location.name }}</template>
        <span v-else class="opacity-60">Project #{{ project.id }}</span>
        <span v-if="project.total_price" class="ml-2 font-medium">{{ project.total_price }}</span>
      </span>
      <span
        v-if="project.pending_closure_count"
        class="rounded-token bg-primary/15 px-2 py-0.5 text-xs font-medium text-primary"
      >
        🔔 {{ project.pending_closure_count }}
      </span>
      <span class="text-xs opacity-50">{{ expanded ? '▴' : '▾' }}</span>
    </button>

    <!-- Expanded: the project's whole story -->
    <div v-if="expanded" class="space-y-3 border-t border-border p-3">
      <!-- Lifecycle actions -->
      <div class="flex flex-wrap items-center gap-1.5">
        <template v-if="!isClosed">
          <BaseButton v-if="canDirectDeal() && !project.active_deal" variant="ghost" class="!px-2 !py-1 text-xs" @click="directDealOpen = true">
            💼 Direct deal…
          </BaseButton>
          <BaseButton v-if="canManage()" variant="ghost" class="!px-2 !py-1 text-xs" @click="openShift">
            📋 Shift to desire…
          </BaseButton>
          <BaseButton v-if="canManage()" variant="ghost" class="!px-2 !py-1 text-xs" @click="archiveProject">
            Archive
          </BaseButton>
          <BaseButton v-if="canManage()" variant="ghost" class="!px-2 !py-1 text-xs text-danger" @click="removeProject">
            Remove
          </BaseButton>
        </template>
        <template v-else>
          <span class="text-xs opacity-70">
            Closed{{ project.closure_reason ? ` — ${project.closure_reason}` : '' }}.
            <template v-if="project.closed_to_desire">Waiting for a desire match; a new call reopens it.</template>
            <template v-else>Nothing can be logged until it is reactivated.</template>
          </span>
          <BaseButton v-if="canManage() && project.status === 'archived'" variant="ghost" class="!px-2 !py-1 text-xs" @click="reactivateProject">
            Reactivate
          </BaseButton>
        </template>
      </div>

      <template v-if="!isClosed">
        <!-- The deal (reserved properties, boxes, close won/lost) -->
        <DealPanel :client-id="clientId" :project-id="project.id" />

        <!-- The property shortlist (what the client wants to see / buy) -->
        <ShortlistPanel :project-id="project.id" @changed="emit('changed')" />

        <!-- Payments: once won with an agreed price -->
        <PaymentsPanel
          v-if="canViewPayments() && project.total_price && project.stage === 'won'"
          :project-id="project.id"
          :total-price="project.total_price"
        />

        <!-- This project's own logs -->
        <TimelinePanel :client-id="clientId" :project-id="project.id" @changed="emit('changed')" />
      </template>
    </div>

    <!-- Shift to desire: archive + re-capture what the client wants (full form). -->
    <BaseModal v-if="shiftOpen" title="Shift to the desire list" @close="shiftOpen = false">
      <p class="mb-3 text-sm opacity-70">
        The client changed their mind — this closes the project and saves what they want, so it
        re-matches (and reopens) when new inventory arrives.
      </p>
      <form class="space-y-3" @submit.prevent="submitShift">
        <DesireFields v-model="shiftForm" />
        <div class="flex gap-2">
          <BaseButton type="submit" :disabled="store.saving">Shift to desire</BaseButton>
          <BaseButton type="button" variant="ghost" @click="shiftOpen = false">Cancel</BaseButton>
        </div>
      </form>
    </BaseModal>

    <!-- Direct deal (deals.direct): pick the properties + the box decision. -->
    <BaseModal v-if="directDealOpen" title="Open a deal directly" @close="directDealOpen = false">
      <p class="mb-3 text-sm opacity-70">
        Deals normally come from a visit log — this direct path reserves the selected properties
        immediately.
      </p>
      <form class="space-y-3" @submit.prevent="submitDirectDeal">
        <ProjectUnitsPicker v-model="directDealUnits" with-boxes />
        <BaseTextarea v-model="directDealNotes" label="Notes (optional)" :rows="2" />
        <div class="flex gap-2">
          <BaseButton type="submit" :disabled="store.saving">Open deal (reserve)</BaseButton>
          <BaseButton type="button" variant="ghost" @click="directDealOpen = false">Cancel</BaseButton>
        </div>
      </form>
    </BaseModal>
  </div>
</template>
