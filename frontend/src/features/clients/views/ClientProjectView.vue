<script setup>
import Swal from 'sweetalert2'
import { computed, onMounted, ref } from 'vue'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { useRoute, useRouter } from 'vue-router'
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
import { desireForm as makeDesireForm, desirePayload } from '@/features/clients/desire'
import DraftBanner from '@/features/drafts/DraftBanner.vue'
import { useModalDraft } from '@/composables/useModalDraft'
import ProjectViewersPanel from '@/features/clients/components/ProjectViewersPanel.vue'
import ShortlistPanel from '@/features/clients/components/ShortlistPanel.vue'
import ProjectUnitsPicker from '@/features/inventory/components/ProjectUnitsPicker.vue'
import PaymentsPanel from '@/features/payments/components/PaymentsPanel.vue'
import TimelinePanel from '@/features/pipeline/components/TimelinePanel.vue'
import ShareToChat from '@/features/collaboration/components/ShareToChat.vue'
import { chatApi } from '@/features/collaboration/api'
import { useClientsStore } from '@/features/clients/clientsStore'
import { useAuthStore } from '@/features/settings/store'
import { useDynamicList } from '@/composables/useDynamicList'
import { BASE_SWAL_OPTS, confirmAction, toastError } from '@/composables/useConfirm'
import { formatDateTime, unitLine as formatUnitLine } from '@/utils/format'
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
const router = useRouter()
const { items: archiveReasons } = useDynamicList('archive_reasons')

// Every project owns a dedicated chat (its communication history between the
// contributors) — this opens it, creating it on first use for older projects.
const openingChat = ref(false)
async function openProjectChat() {
  openingChat.value = true
  try {
    const conversation = await chatApi.projectConversation(props.projectId)
    router.push({ name: 'chat.thread', params: { id: conversation.id } })
  } finally {
    openingChat.value = false
  }
}

const canManage = () => auth.can('projects.manage')
const canDirectDeal = () => auth.can('deals.direct')
const canViewPayments = () => auth.can('versements.view')
const canFreeze = () => auth.can('projects.freeze')

const loading = ref(true)

const project = computed(
  () =>
    [...store.projects, ...store.archivedProjects].find(
      (p) => String(p.id) === String(props.projectId),
    ) ?? null,
)
const isClosed = computed(() => project.value && project.value.status !== 'active')

const unitLine = formatUnitLine

// Payments track EACH apartment alone: one panel per apartment that carries an
// agreed price on ANY of the project's deals (several deals may coexist) —
// won now, or won-then-released (its recorded payments stay visible as
// refundable history). Legacy wins (closed before per-apartment tracking)
// fall back to one project-level panel scoped to the stamped unit.
const paymentUnits = computed(() => {
  const deals = store.deals[props.projectId] ?? []
  const byUnit = new Map()
  for (const d of deals) {
    for (const u of (d.units ?? []).filter((x) => x.agreed_price != null)) {
      const prev = byUnit.get(u.id)
      if (!prev || (prev.state !== 'won' && u.state === 'won')) byUnit.set(u.id, u)
    }
  }
  if (byUnit.size) {
    return [...byUnit.values()].map((u) => ({
      id: u.id,
      released: u.state !== 'won',
      label: u.reference + (u.state !== 'won' ? ' (released)' : ''),
      price: u.agreed_price,
    }))
  }
  if (project.value?.stage !== 'won') return []
  return [
    {
      id: project.value?.unit?.id ?? null,
      released: false,
      label: project.value?.unit?.reference ?? null,
      price: project.value?.total_price ?? null,
    },
  ]
})

async function refresh() {
  await Promise.all([
    store.load(props.id),
    store.loadProjects(props.id),
    store.loadArchivedProjects(props.id),
    store.loadDeals(props.projectId),
  ])
}

onMounted(async () => {
  try {
    await Promise.all([refresh(), store.loadDesire(props.id)])
  } finally {
    loading.value = false
  }
})
// pull-to-refresh (APK)
useRefreshable(() => Promise.all([refresh(), store.loadDesire(props.id)]))

// --- Shift to desire (modal): archive the project + re-capture the wants ---
const shiftOpen = ref(false)
const shiftForm = ref(makeDesireForm())

// The modal's state lives in this view (it never unmounts on close), so the
// draft follows the open flag instead of the component lifecycle.
const shiftDraft = useModalDraft({
  key: () => `shift-desire:${props.projectId}`,
  label: 'Shift to desire',
  active: () => shiftOpen.value,
  getForm: () => shiftForm.value,
  setForm: (d) => (shiftForm.value = { ...makeDesireForm(), ...d }),
})

function openShift() {
  shiftForm.value = makeDesireForm(store.desire)
  shiftOpen.value = true
}

// Notes are required — the story behind the numbers (matches the server rule).
const shiftReady = computed(() => !!(shiftForm.value.notes ?? '').trim())

async function submitShift() {
  if (!shiftReady.value) return
  try {
    await store.shiftProjectToDesire(props.id, props.projectId, desirePayload(shiftForm.value))
    shiftDraft.complete()
    shiftOpen.value = false
  } catch {
    /* toast raised by the store */
  }
}

function cancelShift() {
  // Cancel just closes — the draft is kept (resume it from the pencil icon).
  shiftOpen.value = false
}

// --- Archive (reason required; blocked once payments exist) ---
async function archiveProject() {
  const { value, isConfirmed } = await Swal.fire({
    ...BASE_SWAL_OPTS,
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

// --- Freeze / unfreeze (projects.freeze): a deliberate close-down to NEW
// activity — calls, plans, visits, deals and chat stop; payments still flow.
async function freezeProject() {
  const { isConfirmed } = await Swal.fire({
    ...BASE_SWAL_OPTS,
    title: 'Freeze this project?',
    text: 'No new activity can be added (calls, visits, deals, chat) until it is unfrozen. Payments and documents continue.',
    showCancelButton: true,
    confirmButtonText: 'Freeze',
    customClass: { confirmButton: 'plaza-swal-confirm', cancelButton: 'plaza-swal-cancel' },
  })
  if (!isConfirmed) return
  await store.freezeProject(props.id, props.projectId)
}

async function unfreezeProject() {
  await store.unfreezeProject(props.id, props.projectId)
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

// --- Direct deal (permission-gated): pick properties + boxes, open it now ---
const directDealOpen = ref(false)
const directDealUnits = ref([])
const directDealNotes = ref('')

const directDealDraft = useModalDraft({
  key: () => `direct-deal:${props.projectId}`,
  label: 'Direct deal',
  active: () => directDealOpen.value,
  getForm: () => ({ units: directDealUnits.value, notes: directDealNotes.value }),
  setForm: (d) => {
    directDealUnits.value = d.units ?? []
    directDealNotes.value = d.notes ?? ''
  },
})

function cancelDirectDeal() {
  // Cancel just closes — the draft is kept (resume it from the pencil icon).
  directDealOpen.value = false
}

// ?resume=<key> (drafts indicator): reopen the right modal with its draft.
const route = useRoute()
onMounted(() => {
  const resume = route.query.resume
  if (resume === `shift-desire:${props.projectId}`) shiftOpen.value = true
  if (resume === `direct-deal:${props.projectId}`) directDealOpen.value = true
})

async function submitDirectDeal() {
  const units = directDealUnits.value
    .filter((p) => p.shortlistable_type === 'unit')
    .map((p) => ({ unit_id: p.shortlistable_id, box_ids: p.box_ids ?? [] }))
  if (!units.length) {
    toastError('Pick at least one apartment / local for the deal.')
    return
  }
  try {
    await store.createDeal(props.id, props.projectId, {
      units,
      notes: directDealNotes.value.trim() || null,
    })
    directDealDraft.complete()
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
            <!-- Freeze = deliberate close-down to new activity (payments flow). -->
            <Button
              v-if="canFreeze() && !project.frozen_at"
              label="Freeze"
              icon="pi pi-lock"
              size="small"
              severity="secondary"
              outlined
              @click="freezeProject"
            />
            <Button
              v-else-if="canFreeze() && project.frozen_at"
              label="Unfreeze"
              icon="pi pi-lock-open"
              size="small"
              @click="unfreezeProject"
            />
            <!-- Several deals may be open at once — one per committed apartment. -->
            <Button
              v-if="canDirectDeal()"
              v-tooltip.bottom="project.frozen ? 'This project is frozen' : null"
              label="Direct deal"
              icon="pi pi-briefcase"
              size="small"
              :disabled="!!project.frozen"
              @click="directDealOpen = true"
            />
            <!-- An open deal holds Interested inventory — close it (won / lost)
                 before the project can leave the pipeline. -->
            <Button
              v-if="canManage()"
              v-tooltip.bottom="project.active_deal ? 'Close the deal (won / lost) first' : null"
              label="Shift to desire"
              icon="pi pi-heart"
              size="small"
              severity="secondary"
              outlined
              :disabled="!!project.active_deal"
              @click="openShift"
            />
            <Button
              v-if="canManage()"
              v-tooltip.bottom="project.active_deal ? 'Close the deal (won / lost) first' : null"
              label="Archive"
              icon="pi pi-inbox"
              size="small"
              severity="secondary"
              outlined
              :disabled="!!project.active_deal"
              @click="archiveProject"
            />
            <Button
              v-if="canManage() && project.is_empty"
              v-tooltip.bottom="'Only an empty project can be removed'"
              label="Remove"
              icon="pi pi-trash"
              size="small"
              severity="danger"
              outlined
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
          <Button
            v-if="auth.can('chat.use') && project.can_view_collaborators"
            label="Project chat"
            icon="pi pi-comments"
            size="small"
            severity="secondary"
            outlined
            :loading="openingChat"
            @click="openProjectChat"
          />
          <ShareToChat
            v-if="auth.can('chat.use')"
            subject-type="client_project"
            :subject-id="Number(projectId)"
            label="Share"
          />
        </template>
      </PageHeader>

      <!-- Explicit freeze: the project is closed to NEW activity until unfrozen. -->
      <p
        v-if="project.frozen_at"
        class="mb-4 flex items-center gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2.5 text-sm text-ink dark:border-amber-500/40 dark:bg-amber-500/10"
      >
        <i class="pi pi-lock text-amber-600 dark:text-amber-400" aria-hidden="true" />
        This project is frozen — no new activity can be added (calls, visits, deals, chat).
        Payments and documents still flow.
      </p>

      <!-- Awaiting dispatch: the in-site plan is in the pool with no field agent
           chosen yet — shown until a dispatcher assigns the in-site agent. -->
      <p
        v-if="project.awaiting_in_site_agent"
        class="mb-4 flex items-center gap-2 rounded-lg border border-sky-300 bg-sky-50 px-3 py-2.5 text-sm text-ink dark:border-sky-500/40 dark:bg-sky-500/10"
      >
        <i class="pi pi-user-plus text-sky-600 dark:text-sky-400" aria-hidden="true" />
        Waiting for the dispatcher to assign the in-site agent for this project — the
        field visit is queued on the dispatch board.
      </p>

      <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <!-- Main column: deal → shortlist → payments → interaction timeline -->
        <div class="space-y-5 xl:col-span-2">
          <template v-if="!isClosed">
            <DealPanel :client-id="id" :project-id="project.id" @changed="refresh" />
            <ShortlistPanel :project-id="project.id" @changed="refresh" />
          </template>
          <!-- One payments panel per apartment with an agreed price — each
               tracked alone. Released apartments keep their history (refunds),
               and an archived project still shows the money story. -->
          <template v-if="canViewPayments()">
            <PaymentsPanel
              v-for="pu in paymentUnits"
              :key="pu.id ?? 'project'"
              :project-id="project.id"
              :total-price="pu.price"
              :unit-id="pu.id"
              :unit-label="paymentUnits.length > 1 || pu.released ? pu.label : null"
            />
          </template>
          <SectionCard>
            <TimelinePanel
              :client-id="id"
              :project-id="project.id"
              :frozen="project.frozen"
              :deal-settled="project.stage === 'won' || !!project.active_deal"
              @changed="refresh"
            />
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

          <!-- "Who can see this project" is collaborator identity — shown only to
               members / detail-trusted users (backend sets can_view_collaborators),
               so a name-only looker cannot discover whose client this is. -->
          <ProjectViewersPanel
            v-if="project.can_view_collaborators"
            :project-id="Number(projectId)"
          />

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
        <DraftBanner :visible="shiftDraft.restored.value" />
        <DesireFields v-model="shiftForm" />
        <div class="flex gap-2">
          <Button
            type="submit"
            label="Shift to desire"
            icon="pi pi-heart"
            :loading="store.saving"
            :disabled="!shiftReady"
          />
          <Button type="button" label="Cancel" severity="secondary" outlined @click="cancelShift" />
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
        Deals normally come from a visit log — this direct path marks the selected properties Interested
        immediately.
      </p>
      <form class="space-y-4" @submit.prevent="submitDirectDeal">
        <DraftBanner :visible="directDealDraft.restored.value" />
        <!-- Deal context: apartments only — a box always rides WITH an apartment. -->
        <ProjectUnitsPicker v-model="directDealUnits" units-only with-boxes />
        <BaseTextarea v-model="directDealNotes" label="Notes" :rows="2" />
        <div class="flex gap-2">
          <Button
            type="submit"
            label="Open deal"
            icon="pi pi-lock"
            :loading="store.saving"
          />
          <Button
            type="button"
            label="Cancel"
            severity="secondary"
            outlined
            @click="cancelDirectDeal"
          />
        </div>
      </form>
    </BaseModal>
  </div>
</template>
