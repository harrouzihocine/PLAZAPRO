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
import { t } from '@/i18n'

const items = ref([])
const loading = ref(false)
const busy = ref(null) // request id currently being resolved

async function load() {
  loading.value = true
  try {
    items.value = await duplicateRequestsApi.list()
  } catch (e) {
    toastError(e.response?.data?.message ?? t('dup.loadFailed'))
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
    toastError(e.response?.data?.message ?? t('dup.resolveFailed'))
  } finally {
    busy.value = null
  }
}

async function share(req, project) {
  const finder = req.requested_by?.name ?? t('dup.theFinder')
  const ok = await confirmAction({
    title: t('dup.shareTitle'),
    text: t('dup.shareText', { finder }),
    confirmText: t('dup.shareConfirm'),
  })
  if (ok)
    resolve(
      req,
      { action: 'share_project', project_id: project.id, share_details: true },
      t('dup.shared'),
    )
}

async function fork(req, project) {
  const finder = req.requested_by?.name ?? t('dup.theFinder')
  const ok = await confirmAction({
    title: t('dup.forkTitle'),
    text: t('dup.forkText', { finder }),
    confirmText: t('dup.forkConfirm'),
  })
  if (ok) resolve(req, { action: 'fork_project', project_id: project.id }, t('dup.forked'))
}

async function deny(req) {
  const ok = await confirmAction({
    title: t('dup.denyTitle'),
    text: t('dup.denyText'),
    danger: true,
    confirmText: t('dup.deny'),
  })
  if (ok) resolve(req, { action: 'deny' }, t('dup.denied'))
}
</script>

<template>
  <div>
    <PageHeader :title="$t('dup.title')" :subtitle="$t('dup.subtitle')" />

    <p v-if="loading" class="py-6 text-center text-sm text-mute">{{ $t('common.loading') }}</p>

    <SectionCard v-else-if="!items.length">
      <EmptyState icon="pi pi-users" :title="$t('dup.emptyTitle')" :body="$t('dup.emptyBody')" />
    </SectionCard>

    <div v-else class="space-y-3">
      <SectionCard v-for="req in items" :key="req.id">
        <!-- Who tried, and which existing client it collided with. -->
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="text-sm text-ink">
              <span class="font-semibold">{{ req.requested_by?.name ?? $t('dup.aUser') }}</span>
              {{ $t('dup.triedToAdd') }}
            </p>
            <p class="mt-1 text-sm text-mute">
              {{ $t('dup.existingClient') }}
              <span class="font-medium text-ink">{{ req.existing_client?.name }}</span>
              <span v-if="req.existing_client?.phone" class="num">
                · <span class="ltr-data">{{ req.existing_client.phone }}</span></span
              >
            </p>
            <p class="mt-0.5 text-xs text-mute">{{ formatDateTime(req.created_at) }}</p>
          </div>
          <Button
            :label="$t('dup.deny')"
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
            {{ $t('dup.noProjects') }}
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
