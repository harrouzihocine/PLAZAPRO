<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import BaseCard from '@/components/base/BaseCard.vue'
import ProjectPanel from '@/features/clients/components/ProjectPanel.vue'
import { useClientsStore } from '@/features/clients/clientsStore'
import { useAuthStore } from '@/features/settings/store'
import { formatPhone } from '@/data/countryCodes'
import ShareToChat from '@/features/collaboration/components/ShareToChat.vue'
import TimelinePanel from '@/features/pipeline/components/TimelinePanel.vue'

// The client file, project-centric: the profile on one side and the PROJECTS the
// client is engaging with on the other — each project card expands into its own
// story (logs, shortlist, deal, payments), so nothing is buried in one long page.
// Heavy forms live in modals (see ProjectPanel / TimelinePanel).
const props = defineProps({ id: { type: [String, Number], required: true } })
const store = useClientsStore()
const auth = useAuthStore()

// Client ownership (assigned agent + who created it/when) is back-office-only,
// gated by clients.manage (super-admin / admin / manager).
const canSeeOwnership = () => auth.can('clients.manage')
const canManage = () => auth.can('clients.manage')
const fmtDateTime = (v) =>
  v ? new Date(v).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—'

const expandedId = ref(null)
const showClosed = ref(false)

const activeProjects = computed(() => store.projects)
const closedProjects = computed(() => store.archivedProjects)

// A workflow move can flip has_calls / step badges — refetch everything shown.
async function refresh() {
  await Promise.all([
    store.load(props.id),
    store.loadProjects(props.id),
    store.loadArchivedProjects(props.id),
  ])
  // Keep a sensible default: the single project auto-expands.
  if (activeProjects.value.length === 1) expandedId.value = activeProjects.value[0].id
}

onMounted(async () => {
  await store.load(props.id)
  await store.loadDesire(props.id)
  if (store.current?.has_calls) {
    await Promise.all([store.loadProjects(props.id), store.loadArchivedProjects(props.id)])
    if (activeProjects.value.length === 1) expandedId.value = activeProjects.value[0].id
  }
})

function newProject() {
  store.createProject(props.id)
}
</script>

<template>
  <div class="space-y-4">
    <RouterLink :to="{ name: 'clients' }" class="text-sm opacity-70 hover:text-primary">← All clients</RouterLink>

    <p v-if="store.loading && !store.current" class="py-4 text-center text-sm opacity-60">Loading…</p>

    <template v-else-if="store.current">
      <!-- Identity header -->
      <div class="flex flex-col gap-1">
        <div class="flex items-center gap-2">
          <h1 class="text-xl font-semibold">{{ store.current.full_name }}</h1>
          <span
            v-if="store.current.status === 'cancelled'"
            class="rounded-token bg-surface px-2 py-0.5 text-xs opacity-70"
          >
            cancelled
          </span>
        </div>
        <p class="opacity-70">{{ formatPhone(store.current.phone) }}<template v-if="store.current.email"> · {{ store.current.email }}</template></p>
        <div v-if="auth.can('chat.use')" class="pt-1">
          <ShareToChat subject-type="client" :subject-id="store.current.id" label="Share client to chat" />
        </div>
      </div>

      <div class="grid gap-4 lg:grid-cols-3">
        <!-- Profile -->
        <BaseCard class="lg:col-span-1 self-start">
          <h2 class="mb-3 text-sm font-semibold uppercase opacity-60">Profile</h2>
          <dl class="space-y-2 text-sm">
            <div class="flex justify-between gap-2">
              <dt class="opacity-60">Source</dt>
              <dd>{{ store.current.source?.label ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-2">
              <dt class="opacity-60">Rating</dt>
              <dd>{{ store.current.rating?.label ?? '—' }}</dd>
            </div>
            <div v-if="canSeeOwnership()" class="flex justify-between gap-2">
              <dt class="opacity-60">Assigned agent</dt>
              <dd>{{ store.current.assigned_agent?.name ?? 'Unassigned' }}</dd>
            </div>
            <div v-if="canSeeOwnership()" class="flex justify-between gap-2">
              <dt class="opacity-60">Created by</dt>
              <dd>{{ store.current.created_by?.name ?? '—' }}</dd>
            </div>
            <div v-if="canSeeOwnership()" class="flex justify-between gap-2">
              <dt class="opacity-60">Created</dt>
              <dd>{{ fmtDateTime(store.current.created_at) }}</dd>
            </div>
          </dl>
          <p v-if="store.current.notes" class="mt-3 whitespace-pre-line border-t border-border pt-3 text-sm opacity-80">
            {{ store.current.notes }}
          </p>
        </BaseCard>

        <div class="space-y-4 lg:col-span-2">
          <!-- Workflow rule: a call is the first entity on a new client. -->
          <template v-if="!store.current.has_calls">
            <div class="rounded-token border border-primary/40 bg-primary/5 px-3 py-2 text-sm">
              📞 <span class="font-medium">New client — log the first call.</span>
              <span class="opacity-70">Projects, visits and requirements unlock after the qualifying call.</span>
            </div>
            <BaseCard>
              <TimelinePanel :client-id="store.current.id" @changed="refresh" />
            </BaseCard>
          </template>

          <template v-else>
            <!-- The projects the client is engaging with -->
            <BaseCard>
              <div class="mb-2 flex items-center justify-between">
                <h2 class="text-sm font-semibold uppercase opacity-60">
                  Projects ({{ activeProjects.length }})
                </h2>
                <button
                  v-if="canManage()"
                  type="button"
                  class="text-xs opacity-60 hover:opacity-100"
                  @click="newProject"
                >
                  + New project
                </button>
              </div>

              <div class="space-y-2">
                <ProjectPanel
                  v-for="p in activeProjects"
                  :key="p.id"
                  :client-id="store.current.id"
                  :project="p"
                  :expanded="expandedId === p.id"
                  @toggle="expandedId = expandedId === p.id ? null : p.id"
                  @changed="refresh"
                />
                <p v-if="!activeProjects.length" class="py-2 text-sm opacity-60">
                  No open project — log a call and select properties to open one.
                </p>
              </div>

              <!-- Closed projects (archived / on the desire list), reactivatable. -->
              <div class="mt-3 border-t border-border pt-2">
                <button
                  type="button"
                  class="text-xs font-semibold uppercase opacity-60 hover:opacity-100"
                  @click="showClosed = !showClosed"
                >
                  {{ showClosed ? 'Hide' : 'Show' }} closed ({{ closedProjects.length }})
                </button>
                <div v-if="showClosed" class="mt-2 space-y-2">
                  <ProjectPanel
                    v-for="p in closedProjects"
                    :key="p.id"
                    :client-id="store.current.id"
                    :project="p"
                    :expanded="expandedId === p.id"
                    @toggle="expandedId = expandedId === p.id ? null : p.id"
                    @changed="refresh"
                  />
                  <p v-if="!closedProjects.length" class="py-1 text-sm opacity-60">No closed projects.</p>
                </div>
              </div>
            </BaseCard>

            <!-- The client-level story (qualifying calls before any project). -->
            <BaseCard v-if="!activeProjects.length">
              <TimelinePanel :client-id="store.current.id" @changed="refresh" />
            </BaseCard>
          </template>
        </div>
      </div>
    </template>

    <p v-else class="py-4 text-center text-sm opacity-60">Client not found.</p>
  </div>
</template>
