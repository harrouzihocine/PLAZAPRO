<script setup>
// Supervised duplicate-client queue. When a user tries to add a client whose
// phone already belongs to a client they can't see, the attempt lands here. For
// each of the existing client's projects the resolver can INSPECT it and then
// resolve THREE ways:
//   • Deny             — the finder gets nothing.
//   • Share project    — the finder JOINS this project (a merge): they see the
//                        client's details and who is working it.
//   • Start separate   — the finder gets their OWN new project on the client,
//                        continuing this one but siloed from it (they never see
//                        this project or who is behind it; the client's own agent
//                        never sees theirs). Managers see both sides.
// Goal: no user can silently take another user's client. Gated by
// clients.duplicates.resolve.
import { onMounted, ref } from 'vue'
import Button from 'primevue/button'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import DuplicateProjectRow from '@/features/oversight/components/DuplicateProjectRow.vue'
import { duplicateRequestsApi } from '@/features/clients/api'
import { confirmAction, toastError, toastSuccess } from '@/composables/useConfirm'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { formatDateTime } from '@/utils/format'

const items = ref([])
const loading = ref(false)
const busy = ref(null) // request id currently being resolved

async function load() {
  loading.value = true
  try {
    items.value = await duplicateRequestsApi.list()
  } catch (e) {
    toastError(e.response?.data?.message ?? 'Could not load duplicate requests.')
  } finally {
    loading.value = false
  }
}
onMounted(load)
useRefreshable(load) // pull-to-refresh + reconnect self-heal

async function resolve(req, payload, successMsg) {
  busy.value = req.id
  try {
    await duplicateRequestsApi.resolve(req.id, payload)
    toastSuccess(successMsg)
    await load()
  } catch (e) {
    toastError(e.response?.data?.message ?? 'Could not resolve the request.')
  } finally {
    busy.value = null
  }
}

async function share(req, project) {
  const finder = req.requested_by?.name ?? 'The finder'
  const ok = await confirmAction({
    title: 'Share this project?',
    text: `${finder} will join this project as a contributor — seeing the client's details and who is working it. One shared workspace.`,
    confirmText: 'Share project',
  })
  if (ok) resolve(req, { action: 'share_project', project_id: project.id, share_details: true }, 'Project shared.')
}

async function fork(req, project) {
  const finder = req.requested_by?.name ?? 'The finder'
  const ok = await confirmAction({
    title: 'Start a separate project?',
    text: `${finder} gets their OWN new project on this client, continuing this one but siloed from it: they won't see this project or who is behind it, and the client's own agent won't see theirs.`,
    confirmText: 'Start separate project',
  })
  if (ok) resolve(req, { action: 'fork_project', project_id: project.id }, 'Separate project created.')
}

async function deny(req) {
  const ok = await confirmAction({
    title: 'Deny this request?',
    text: 'The finder gets nothing — no client or project is shared with them.',
    danger: true,
    confirmText: 'Deny',
  })
  if (ok) resolve(req, { action: 'deny' }, 'Request denied.')
}
</script>

<template>
  <div>
    <PageHeader
      title="Duplicate clients"
      subtitle="Resolve blocked create attempts — inspect a project, then deny, share it, or start a separate one."
    />

    <p v-if="loading" class="py-6 text-center text-sm text-mute">Loading…</p>

    <SectionCard v-else-if="!items.length">
      <EmptyState
        icon="pi pi-users"
        title="No pending duplicates"
        body="When a user tries to add a client whose phone already exists, it appears here."
      />
    </SectionCard>

    <div v-else class="space-y-3">
      <SectionCard v-for="req in items" :key="req.id">
        <!-- Who tried, and which existing client it collided with. -->
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="text-sm text-ink">
              <span class="font-semibold">{{ req.requested_by?.name ?? 'A user' }}</span>
              tried to add a client that already exists.
            </p>
            <p class="mt-1 text-sm text-mute">
              Existing client:
              <span class="font-medium text-ink">{{ req.existing_client?.name }}</span>
              <span v-if="req.existing_client?.phone" class="num"> · {{ req.existing_client.phone }}</span>
            </p>
            <p class="mt-0.5 text-xs text-mute">{{ formatDateTime(req.created_at) }}</p>
          </div>
          <Button
            label="Deny"
            icon="pi pi-times"
            size="small"
            severity="danger"
            outlined
            :loading="busy === req.id"
            @click="deny(req)"
          />
        </div>

        <!-- The existing client's projects: inspect one, then choose how to resolve. -->
        <div class="mt-3 border-t border-line pt-3">
          <p v-if="!req.existing_client?.projects?.length" class="text-sm text-mute">
            This client has no projects to share.
          </p>
          <ul v-else class="space-y-2">
            <DuplicateProjectRow
              v-for="p in req.existing_client.projects"
              :key="p.id"
              :req-id="req.id"
              :client-id="req.existing_client.id"
              :project="p"
              :busy="busy === req.id"
              @share="share(req, p)"
              @fork="fork(req, p)"
            />
          </ul>
        </div>
      </SectionCard>
    </div>
  </div>
</template>
