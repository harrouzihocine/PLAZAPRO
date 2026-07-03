<script setup>
import Swal from 'sweetalert2'
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import BaseModal from '@/components/base/BaseModal.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import StatusTag from '@/components/ui/StatusTag.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ActivityTimeline from '@/components/ui/ActivityTimeline.vue'
import DealPanel from '@/features/clients/components/DealPanel.vue'
import DesireFields from '@/features/clients/components/DesireFields.vue'
import ProjectViewersPanel from '@/features/clients/components/ProjectViewersPanel.vue'
import ShortlistPanel from '@/features/clients/components/ShortlistPanel.vue'
import ProjectUnitsPicker from '@/features/inventory/components/ProjectUnitsPicker.vue'
import PaymentsPanel from '@/features/payments/components/PaymentsPanel.vue'
import TimelinePanel from '@/features/pipeline/components/TimelinePanel.vue'
import ShareToChat from '@/features/collaboration/components/ShareToChat.vue'
import { useClientsStore } from '@/features/clients/clientsStore'
import { useAuthStore } from '@/features/settings/store'
import { useDynamicList } from '@/composables/useDynamicList'
import { confirmAction, toastError } from '@/composables/useConfirm'
import { formatDateTime } from '@/utils/format'
import { formatMoney } from '@/features/payments/money'

// ONE project engagement, on its own page: the full story — deal, shortlist,
// payments, interaction timeline and audit history — with the lifecycle actions
// (direct deal / shift to desire / archive / remove) in the header. The client
// file links here; nothing about a project is buried in an accordion anymore.
const props = defineProps({
  id: { type: [String, Number], required: true }, // client id
  projectId: { type: [String, Number], required: true },
})

const store = useClientsStore()
const auth = useAuthStore()
const { items: archiveReasons } = useDynamicList('archive_reasons')

const canManage = () => auth.can('clients.manage')
const canDirectDeal = () => auth.can('deals.direct')
const canViewPayments = () => auth.can('versements.view')

const loading = ref(true)

const project = computed(
  () =>
    [...store.projects, ...store.archivedProjects].find(
      (p) => String(p.id) === String(props.projectId),
    ) ?? null,
)
const isClosed = computed(() => project.value && project.value.status !== 'active')

const unitLine = (u) =>
  [u.reference, u.type, u.floor, u.area_sqm ? `${u.area_sqm} m²` : null].filter(Boolean).join(' · ')

async function refresh() {
  await Promise.all([
    store.load(props.id),
    store.loadProjects(props.id),
    store.loadArchivedProjects(props.id),
  ])
}

onMounted(async () => {
  try {
    await Promise.all([refresh(), store.loadDesire(props.id)])
  } finally {
    loading.value = false
  }
})

// --- Shift to desire (modal): archive the project + re-capture the wants ---
const shiftOpen = ref(false)
const shiftForm = ref({
  wilaya_id: '',
  commune_id: '',
  type_id: '',
  budget_min: '',
  budget_max: '',
  floor_pref: '',
  notes: '',
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
    await store.shiftProjectToDesire(props.id, props.projectId, {
      wilaya_id: shiftForm.value.wilaya_id || null,
      commune_id: shiftForm.value.commune_id || null,
      type_id: shiftForm.value.type_id || null,
      budget_min: shiftForm.value.budget_min === '' ? null : Number(shiftForm.value.budget_min),
      budget_max: shiftForm.value.budget_max === '' ? null : Number(shiftForm.value.budget_max),
      floor_pref: (shiftForm.value.floor_pref ?? '').trim() || null,
      notes: (shiftForm.value.notes ?? '').trim() || null,
    })
    shiftOpen.value = false
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
    await store.archiveProject(props.id, props.projectId, {
      archive_reason_id: Number(value),
    })
  }
}

async function reactivateProject() {
  await store.reactivateProject(props.id, props.projectId)
}

// Removing is reserved for empty projects (nothing ever logged on them) — a
// filled project is part of the client's story and is archived instead.
async function removeProject() {
  if (
    await confirmAction({
      title: 'Remove this empty project?',
      text: 'The record is kept but marked cancelled.',
      confirmText: 'Remove',
      danger: true,
    })
  ) {
    await store.cancelProject(props.id, props.projectId)
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
    await store.createDeal(props.id, props.projectId, {
      units,
      notes: directDealNotes.value.trim() || null,
    })
    directDealOpen.value = false
    directDealUnits.value = []
    directDealNotes.value = ''
  } catch {
    /* toast raised by the store */
  }
}
</script>

<template>
  <div>
    <div v-if="loading && !project" class="space-y-4">
      <Skeleton width="18rem" height="2rem" />
      <Skeleton height="12rem" />
    </div>

    <EmptyState
      v-else-if="!project"
      icon="pi pi-folder-open"
      title="Project not found"
      body="It may have been removed, or belongs to another client."
    />

    <template v-else>
      <PageHeader
        :title="
          project.unit
            ? unitLine(project.unit)
            : (project.location?.name ?? `Project #${project.id}`)
        "
        :back="{ name: 'clients.file', params: { id } }"
      >
        <template #back-label>{{ store.current?.full_name ?? 'Client file' }}</template>
        <template #badges>
          <StatusTag :value="project.step" />
          <StatusTag v-if="isClosed && project.status !== 'archived'" :value="project.status" />
        </template>
        <template #subtitle>
          <span class="flex flex-wrap items-center gap-x-2">
            <span>Project #{{ project.id }}</span>
            <span v-if="project.location?.name && project.unit">· {{ project.location.name }}</span>
            <span v-if="project.total_price" class="num font-medium text-ink">
              · {{ formatMoney(project.total_price) }}
            </span>
            <span v-if="isClosed" class="text-mute">
              · Closed{{ project.closure_reason ? ` — ${project.closure_reason}` : '' }}
              <template v-if="project.closed_to_desire">
                (waiting for a desire match; a new call reopens it)
              </template>
            </span>
          </span>
        </template>
        <template #actions>
          <template v-if="!isClosed">
            <Button
              v-if="canDirectDeal() && !project.active_deal"
              label="Direct deal"
              icon="pi pi-briefcase"
              size="small"
              @click="directDealOpen = true"
            />
            <Button
              v-if="canManage()"
              label="Shift to desire"
              icon="pi pi-heart"
              size="small"
              severity="secondary"
              outlined
              @click="openShift"
            />
            <Button
              v-if="canManage()"
              label="Archive"
              icon="pi pi-inbox"
              size="small"
              severity="secondary"
              outlined
              @click="archiveProject"
            />
            <Button
              v-if="canManage() && project.is_empty"
              label="Remove"
              icon="pi pi-trash"
              size="small"
              severity="danger"
              outlined
              v-tooltip.bottom="'Only an empty project can be removed'"
              @click="removeProject"
            />
          </template>
          <Button
            v-else-if="canManage() && project.status === 'archived'"
            label="Reactivate"
            icon="pi pi-undo"
            size="small"
            @click="reactivateProject"
          />
          <ShareToChat
            v-if="auth.can('chat.use')"
            subject-type="client_project"
            :subject-id="Number(projectId)"
            label="Share"
          />
        </template>
      </PageHeader>

      <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <!-- Main column: deal → shortlist → payments → interaction timeline -->
        <div class="space-y-5 xl:col-span-2">
          <template v-if="!isClosed">
            <DealPanel :client-id="id" :project-id="project.id" />
            <ShortlistPanel :project-id="project.id" @changed="refresh" />
            <PaymentsPanel
              v-if="canViewPayments() && project.total_price && project.stage === 'won'"
              :project-id="project.id"
              :total-price="project.total_price"
            />
          </template>
          <SectionCard>
            <TimelinePanel :client-id="id" :project-id="project.id" @changed="refresh" />
          </SectionCard>
        </div>

        <!-- Side column: facts + audit history -->
        <div class="space-y-5">
          <SectionCard title="Details" icon="pi pi-info-circle">
            <dl class="space-y-2.5 text-sm">
              <div class="flex justify-between gap-2">
                <dt class="text-mute">Stage</dt>
                <dd><StatusTag :value="project.stage" /></dd>
              </div>
              <div v-if="project.unit" class="flex justify-between gap-2">
                <dt class="text-mute">Unit</dt>
                <dd class="text-right font-medium text-ink">{{ project.unit.reference }}</dd>
              </div>
              <div v-if="project.unit?.price" class="flex justify-between gap-2">
                <dt class="text-mute">List price</dt>
                <dd class="num text-ink">{{ formatMoney(project.unit.price) }}</dd>
              </div>
              <div v-if="project.total_price" class="flex justify-between gap-2">
                <dt class="text-mute">Agreed price</dt>
                <dd class="num font-semibold text-ink">{{ formatMoney(project.total_price) }}</dd>
              </div>
              <div v-if="project.location" class="flex justify-between gap-2">
                <dt class="text-mute">Location</dt>
                <dd class="text-right text-ink">{{ project.location.name }}</dd>
              </div>
              <div class="flex justify-between gap-2">
                <dt class="text-mute">Opened</dt>
                <dd class="text-ink">{{ formatDateTime(project.created_at) }}</dd>
              </div>
              <div v-if="project.created_by" class="flex justify-between gap-2">
                <dt class="text-mute">Opened by</dt>
                <dd class="text-ink">{{ project.created_by.name }}</dd>
              </div>
            </dl>
          </SectionCard>

          <ProjectViewersPanel :project-id="Number(projectId)" />

          <SectionCard title="History" icon="pi pi-clock">
            <ActivityTimeline :id="Number(projectId)" type="client_project" />
          </SectionCard>
        </div>
      </div>
    </template>

    <!-- Shift to desire: archive + re-capture what the client wants (full form). -->
    <BaseModal v-if="shiftOpen" title="Shift to the desire list" @close="shiftOpen = false">
      <p class="mb-4 text-sm text-mute">
        The client changed their mind — this closes the project and saves what they want, so it
        re-matches (and reopens) when new inventory arrives.
      </p>
      <form class="space-y-4" @submit.prevent="submitShift">
        <DesireFields v-model="shiftForm" />
        <div class="flex gap-2">
          <Button
            type="submit"
            label="Shift to desire"
            icon="pi pi-heart"
            :loading="store.saving"
          />
          <Button
            type="button"
            label="Cancel"
            severity="secondary"
            outlined
            @click="shiftOpen = false"
          />
        </div>
      </form>
    </BaseModal>

    <!-- Direct deal (deals.direct): pick the properties + the box decision. -->
    <BaseModal
      v-if="directDealOpen"
      title="Open a deal directly"
      size="max-w-4xl"
      @close="directDealOpen = false"
    >
      <p class="mb-4 text-sm text-mute">
        Deals normally come from a visit log — this direct path reserves the selected properties
        immediately.
      </p>
      <form class="space-y-4" @submit.prevent="submitDirectDeal">
        <ProjectUnitsPicker v-model="directDealUnits" with-boxes />
        <BaseTextarea v-model="directDealNotes" label="Notes (optional)" :rows="2" />
        <div class="flex gap-2">
          <Button
            type="submit"
            label="Open deal (reserve)"
            icon="pi pi-lock"
            :loading="store.saving"
          />
          <Button
            type="button"
            label="Cancel"
            severity="secondary"
            outlined
            @click="directDealOpen = false"
          />
        </div>
      </form>
    </BaseModal>
  </div>
</template>
