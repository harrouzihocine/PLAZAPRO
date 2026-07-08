<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import Avatar from 'primevue/avatar'
import Badge from 'primevue/badge'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import BaseModal from '@/components/base/BaseModal.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import StatusTag from '@/components/ui/StatusTag.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ActivityTimeline from '@/components/ui/ActivityTimeline.vue'
import ClientFormDrawer from '@/features/clients/components/ClientFormDrawer.vue'
import SendToPhoneButton from '@/features/clients/components/SendToPhoneButton.vue'
import CallLogForm from '@/features/pipeline/components/CallLogForm.vue'
import { useClientsStore } from '@/features/clients/clientsStore'
import { pipelineApi } from '@/features/pipeline/api'
import { toastInfo } from '@/composables/useConfirm'
import { useAuthStore } from '@/features/settings/store'
import { formatPhone } from '@/data/countryCodes'
import { formatDate, formatDateTime, humanize, initials, unitLine as formatUnitLine } from '@/utils/format'
import { formatMoney } from '@/features/payments/money'
import ShareToChat from '@/features/collaboration/components/ShareToChat.vue'

// The client file, project-centric: the profile on one side and the PROJECTS the
// client is engaging with on the other. Each project card is a DOOR — it opens
// the project's own workspace page (deal, shortlist, payments, logs), so nothing
// is buried in one long page. A NEW project starts with its first call log (the
// call modal IS the "new project" form).
const props = defineProps({ id: { type: [String, Number], required: true } })
const store = useClientsStore()
const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

// Client ownership (assigned agent + who created it/when) is back-office-only,
// gated by clients.manage (super-admin / admin / manager).
const canSeeOwnership = () => auth.can('clients.manage')
const canManage = () => auth.can('clients.manage')
// Opening a NEW project is part of the agent's lead workflow — its own grant,
// held by agents alongside clients.create.
const canCreateProject = () => auth.can('projects.create')
// Without clients.view_details only the client's name is shown (no phone/profile).
const canSeeDetails = () => auth.can('clients.view_details')

const showClosed = ref(false)
const showHistory = ref(false)
const editOpen = ref(false)

const activeProjects = computed(() => store.projects)
const closedProjects = computed(() => store.archivedProjects)

// A pending click-to-call reminder rides along to whichever project door the
// agent opens — TimelinePanel there consumes ?logcall and opens the modal.
const projectCardQuery = computed(() =>
  route.query.logcall ? { logcall: route.query.logcall } : {},
)

const unitLine = formatUnitLine

// Opens WhatsApp (app or web) with the client's number — digits only, E.164.
const whatsappLink = (phone) => `https://wa.me/${(phone ?? '').replace(/\D/g, '')}`

const ID_DOCUMENT_LABELS = {
  national_id: 'National ID card',
  driving_license: 'Driving licence',
  passport: 'Passport',
}

// A workflow move can flip step badges — refetch everything shown.
async function refresh() {
  await Promise.all([
    store.load(props.id),
    store.loadProjects(props.id),
    store.loadArchivedProjects(props.id),
  ])
}

onMounted(async () => {
  await store.load(props.id)
  await Promise.all([
    store.loadDesire(props.id),
    store.loadProjects(props.id),
    store.loadArchivedProjects(props.id),
  ])

  // ?logcall=<id> (click-to-call reminder): the client had no single active
  // project to deep-link into. No project at all → a new call IS a new project,
  // so open that modal; several projects → the agent picks the door themselves
  // (the card links carry ?logcall through so the timeline finishes the flow).
  if (route.query.logcall && auth.can('calls.log')) {
    if (!store.projects.length && canCreateProject()) {
      newProjectOpen.value = true
    } else if (store.projects.length > 1) {
      toastInfo('Pick the project to log this call on.')
    }
  }
})
// pull-to-refresh (APK)
useRefreshable(() => Promise.all([refresh(), store.loadDesire(props.id)]))

// --- New project: the first thing captured is its call log. ---
const newProjectOpen = ref(false)

// ?resume=<key> (drafts indicator): reopen the new-project call modal.
if (route.query.resume === `call-log:new-project:${props.id}`) newProjectOpen.value = true

async function submitNewProject(callPayload) {
  try {
    const project = await store.createProject(props.id)
    await store.logCall(props.id, { ...callPayload, client_project_id: project.id })
    newProjectOpen.value = false
    // The click-to-call reminder that led here is answered — best-effort close
    // (other open sessions drop their prompt too).
    if (route.query.logcall) {
      pipelineApi.closeCallRequest(route.query.logcall, 'logged').catch(() => {})
    }
    router.push({ name: 'clients.project', params: { id: props.id, projectId: project.id } })
  } catch {
    /* toast raised by the store; an empty project (if created) can be removed */
  }
}
</script>

<template>
  <div>
    <div v-if="store.loading && !store.current" class="space-y-4">
      <Skeleton width="16rem" height="2rem" />
      <Skeleton height="10rem" />
    </div>

    <EmptyState
      v-else-if="!store.current"
      icon="pi pi-user"
      title="Client not found"
      body="The record may have been removed."
    />

    <template v-else>
      <PageHeader :title="store.current.full_name" :back="{ name: 'clients' }">
        <template #back-label>All clients</template>
        <template #badges>
          <StatusTag v-if="store.current.status === 'cancelled'" value="cancelled" />
        </template>
        <template #subtitle>
          <span v-if="canSeeDetails()" class="inline-flex flex-wrap items-center gap-x-2">
            <span class="num">{{ formatPhone(store.current.phone) }}</span>
            <SendToPhoneButton :client-id="store.current.id" />
            <a
              :href="whatsappLink(store.current.phone)"
              target="_blank"
              rel="noopener"
              class="inline-flex h-6 w-6 items-center justify-center rounded-full text-emerald-600 transition-colors hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-500/10"
              aria-label="Message on WhatsApp"
              title="Message on WhatsApp"
            >
              <i class="pi pi-whatsapp" aria-hidden="true" />
            </a>
            <template v-if="store.current.email">· {{ store.current.email }}</template>
          </span>
        </template>
        <template #actions>
          <Button
            v-if="canManage()"
            label="Edit"
            icon="pi pi-pencil"
            size="small"
            severity="secondary"
            outlined
            @click="editOpen = true"
          />
          <ShareToChat
            v-if="auth.can('chat.use')"
            subject-type="client"
            :subject-id="store.current.id"
            label="Share to chat"
          />
        </template>
      </PageHeader>

      <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <!-- Profile -->
        <div class="space-y-5 self-start lg:sticky lg:top-20">
          <SectionCard title="Profile" icon="pi pi-id-card">
            <div class="mb-4 flex items-center gap-3">
              <Avatar
                :label="initials(store.current.full_name)"
                size="large"
                shape="circle"
                class="!bg-highlight !text-primary-700 dark:!text-primary-300"
              />
              <div class="min-w-0">
                <p class="truncate font-semibold text-ink">{{ store.current.full_name }}</p>
                <p v-if="canSeeDetails()" class="flex items-center gap-1.5 text-sm text-mute">
                  <span class="num truncate">{{ formatPhone(store.current.phone) }}</span>
                  <SendToPhoneButton :client-id="store.current.id" size="sm" />
                  <a
                    :href="whatsappLink(store.current.phone)"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-emerald-600 transition-colors hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-500/10"
                    aria-label="Message on WhatsApp"
                    title="Message on WhatsApp"
                  >
                    <i class="pi pi-whatsapp text-sm" aria-hidden="true" />
                  </a>
                </p>
              </div>
            </div>
            <dl v-if="canSeeDetails()" class="space-y-2.5 text-sm">
              <div class="flex justify-between gap-2">
                <dt class="text-mute">Source</dt>
                <dd class="flex items-center gap-1.5 text-ink">
                  <i
                    v-if="store.current.source?.icon"
                    :class="store.current.source.icon"
                    class="text-xs text-mute"
                    aria-hidden="true"
                  />
                  {{ store.current.source?.label ?? '—' }}
                </dd>
              </div>
              <div v-if="store.current.referrer_name || store.current.referrer_phone" class="flex justify-between gap-2">
                <dt class="text-mute">Referred by</dt>
                <dd class="text-end text-ink">
                  {{ store.current.referrer_name ?? '—' }}
                  <span v-if="store.current.referrer_phone" class="num block text-xs text-mute">
                    {{ formatPhone(store.current.referrer_phone) }}
                  </span>
                </dd>
              </div>
              <div class="flex justify-between gap-2">
                <dt class="text-mute">Rating</dt>
                <dd class="text-ink">{{ store.current.rating?.label ?? '—' }}</dd>
              </div>
              <div v-if="canSeeOwnership()" class="flex justify-between gap-2">
                <dt class="text-mute">Assigned agent</dt>
                <dd class="text-ink">{{ store.current.assigned_agent?.name ?? 'Unassigned' }}</dd>
              </div>
              <div v-if="canSeeOwnership()" class="flex justify-between gap-2">
                <dt class="text-mute">Created by</dt>
                <dd class="text-ink">{{ store.current.created_by?.name ?? '—' }}</dd>
              </div>
              <div v-if="canSeeOwnership()" class="flex justify-between gap-2">
                <dt class="text-mute">Created</dt>
                <dd class="text-ink">{{ formatDateTime(store.current.created_at) }}</dd>
              </div>
            </dl>
            <p
              v-if="canSeeDetails() && store.current.notes"
              class="mt-3 whitespace-pre-line border-t border-line pt-3 text-sm text-mute"
            >
              {{ store.current.notes }}
            </p>
          </SectionCard>

          <!-- Identity / contract details (filled as the deal firms up). -->
          <SectionCard
            v-if="
              canSeeDetails() &&
              (store.current.id_documents?.length ||
                store.current.id_number ||
                store.current.birth_date ||
                store.current.birth_place ||
                store.current.address)
            "
            title="Identity & contract"
            icon="pi pi-id-card"
          >
            <dl class="space-y-2.5 text-sm">
              <!-- Each presented ID document, with its issue date/place. -->
              <div
                v-for="(doc, i) in store.current.id_documents"
                :key="i"
                class="border-b border-line pb-2.5 last:border-0 last:pb-0"
              >
                <div class="flex justify-between gap-2">
                  <dt class="text-mute">{{ ID_DOCUMENT_LABELS[doc.type] ?? humanize(doc.type) }}</dt>
                  <dd class="num text-ink">{{ doc.number || '—' }}</dd>
                </div>
                <div
                  v-if="doc.issued_at || doc.issued_place"
                  class="mt-0.5 text-end text-xs text-mute"
                >
                  <template v-if="doc.issued_at">{{ formatDate(doc.issued_at) }}</template>
                  <template v-if="doc.issued_place"> — {{ doc.issued_place }}</template>
                </div>
              </div>
              <div v-if="store.current.id_number" class="flex justify-between gap-2">
                <dt class="text-mute">ID number (NIN)</dt>
                <dd class="num text-ink">{{ store.current.id_number }}</dd>
              </div>
              <div v-if="store.current.birth_date" class="flex justify-between gap-2">
                <dt class="text-mute">Born</dt>
                <dd class="text-ink">
                  {{ formatDate(store.current.birth_date) }}
                  <template v-if="store.current.birth_place">
                    — {{ store.current.birth_place }}</template
                  >
                </dd>
              </div>
              <div v-if="store.current.address" class="flex justify-between gap-2">
                <dt class="text-mute">Address</dt>
                <dd class="text-end text-ink">{{ store.current.address }}</dd>
              </div>
            </dl>
          </SectionCard>

          <SectionCard v-if="canSeeOwnership()" title="Record history" icon="pi pi-clock" flush>
            <div class="px-4 py-3 sm:px-5">
              <Button
                :label="showHistory ? 'Hide history' : 'Show history'"
                :icon="showHistory ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"
                text
                size="small"
                severity="secondary"
                @click="showHistory = !showHistory"
              />
              <ActivityTimeline v-if="showHistory" :id="Number(id)" type="client" class="mt-3" />
            </div>
          </SectionCard>
        </div>

        <div class="space-y-5 lg:col-span-2">
          <!-- The projects the client is engaging with -->
          <SectionCard title="Projects" icon="pi pi-folder">
            <template #actions>
              <Button
                v-if="canCreateProject()"
                label="New project"
                icon="pi pi-plus"
                size="small"
                text
                @click="newProjectOpen = true"
              />
            </template>

            <div class="space-y-2">
              <RouterLink
                v-for="p in activeProjects"
                :key="p.id"
                :to="{
                  name: 'clients.project',
                  params: { id: store.current.id, projectId: p.id },
                  query: projectCardQuery,
                }"
                class="group flex items-center gap-3 rounded-xl border border-line p-3 transition-colors hover:border-primary-300 hover:bg-surface-50 dark:hover:bg-surface-800"
              >
                <span
                  class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-highlight text-primary-700 dark:text-primary-300"
                >
                  <i :class="p.unit ? 'pi pi-home' : 'pi pi-folder'" aria-hidden="true" />
                </span>
                <span class="min-w-0 flex-1">
                  <span class="flex flex-wrap items-center gap-2">
                    <span class="truncate text-sm font-semibold text-ink">
                      <template v-if="p.unit">{{ unitLine(p.unit) }}</template>
                      <template v-else-if="p.location">{{ p.location.name }}</template>
                      <template v-else>Project #{{ p.id }}</template>
                    </span>
                    <StatusTag :value="p.step" />
                    <!-- Oversight-only: this is a duplicate-resolution "separate
                         project", a continuation of an earlier engagement rather
                         than a fresh first-contact. Only view_all sees the link. -->
                    <span
                      v-if="p.continued_from"
                      v-tooltip.top="`Continues project #${p.continued_from.id} — a separate engagement started for another agent on this client`"
                      class="inline-flex items-center gap-1 rounded-full border border-line px-2 py-0.5 text-[11px] text-mute"
                    >
                      <i class="pi pi-link text-[10px]" aria-hidden="true" /> Continuation
                    </span>
                  </span>
                  <span class="mt-0.5 block truncate text-xs text-mute">
                    <template v-if="p.location?.name && p.unit"
                      >{{ p.location.name }} ·
                    </template>
                    <template v-if="p.total_price">
                      <span class="num font-medium">{{ formatMoney(p.total_price) }}</span> ·
                    </template>
                    opened {{ formatDateTime(p.created_at) }}
                    <template v-if="p.created_by?.name"> by {{ p.created_by.name }}</template>
                  </span>
                </span>
                <Badge
                  v-if="p.pending_closure_count"
                  v-tooltip.top="'Liked properties awaiting won / lost'"
                  :value="p.pending_closure_count"
                  severity="warn"
                />
                <i
                  class="pi pi-arrow-right text-sm text-mute transition-transform group-hover:translate-x-0.5 group-hover:text-ink"
                  aria-hidden="true"
                />
              </RouterLink>

              <EmptyState
                v-if="!activeProjects.length"
                icon="pi pi-folder-open"
                title="No open project"
                body="A new project starts with its first call log."
              />
            </div>

            <!-- Closed projects (archived / on the desire list), reactivatable. -->
            <div class="mt-4 border-t border-line pt-3">
              <Button
                :label="`${showClosed ? 'Hide' : 'Show'} closed (${closedProjects.length})`"
                :icon="showClosed ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"
                text
                size="small"
                severity="secondary"
                @click="showClosed = !showClosed"
              />
              <div v-if="showClosed" class="mt-2 space-y-2">
                <RouterLink
                  v-for="p in closedProjects"
                  :key="p.id"
                  :to="{
                    name: 'clients.project',
                    params: { id: store.current.id, projectId: p.id },
                  }"
                  class="group flex items-center gap-3 rounded-xl border border-dashed border-line p-3 opacity-90 transition-colors hover:border-primary-300 hover:opacity-100"
                >
                  <span
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-surface-100 text-mute dark:bg-surface-800"
                  >
                    <i
                      :class="p.closed_to_desire ? 'pi pi-heart' : 'pi pi-inbox'"
                      aria-hidden="true"
                    />
                  </span>
                  <span class="min-w-0 flex-1">
                    <span class="flex flex-wrap items-center gap-2">
                      <span class="truncate text-sm font-medium text-ink">
                        <template v-if="p.unit">{{ unitLine(p.unit) }}</template>
                        <template v-else-if="p.location">{{ p.location.name }}</template>
                        <template v-else>Project #{{ p.id }}</template>
                      </span>
                      <StatusTag :value="p.step" />
                    </span>
                    <span class="mt-0.5 block truncate text-xs text-mute">
                      {{ p.closure_reason ?? 'Closed' }}
                      <template v-if="p.closed_to_desire">
                        — waiting for a desire match; a new call reopens it
                      </template>
                    </span>
                  </span>
                  <i class="pi pi-arrow-right text-sm text-mute" aria-hidden="true" />
                </RouterLink>
                <p v-if="!closedProjects.length" class="py-1 text-sm text-mute">
                  No closed projects.
                </p>
              </div>
            </div>
          </SectionCard>
        </div>
      </div>

      <ClientFormDrawer v-model:visible="editOpen" :client="store.current" @saved="refresh" />

      <!-- New project — step one is logging the call that opens it. -->
      <BaseModal
        v-if="newProjectOpen"
        title="New project — log the opening call"
        @close="newProjectOpen = false"
      >
        <p class="mb-4 text-sm text-mute">
          A project starts with a phone call: log it here and the project opens with the call (and
          any qualification) attached.
        </p>
        <CallLogForm
          :client="store.current"
          :field-agents="store.agents"
          :saving="store.saving"
          :desire="store.desire"
          :draft-key="`call-log:new-project:${props.id}`"
          @submit="submitNewProject"
          @cancel="newProjectOpen = false"
        />
      </BaseModal>
    </template>
  </div>
</template>
