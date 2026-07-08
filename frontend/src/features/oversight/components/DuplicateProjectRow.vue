<script setup>
// One of the existing client's projects in the duplicate queue. The resolver can
// INSPECT it (lazy-loaded activity, stage and the client's brief) before choosing
// to Share it (merge) or Start a separate project (siloed continuation). The
// parent owns the confirm dialogs + the resolve call — this row only surfaces the
// content and emits the chosen action.
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import { duplicateRequestsApi } from '@/features/clients/api'
import { useAuthStore } from '@/features/settings/store'
import { formatDateTime, humanize } from '@/utils/format'
import { t } from '@/i18n'

const props = defineProps({
  reqId: { type: Number, required: true },
  clientId: { type: Number, required: true },
  project: { type: Object, required: true },
  busy: { type: Boolean, default: false },
})
defineEmits(['share', 'fork'])

const router = useRouter()
const auth = useAuthStore()

// Open the full project workspace (timeline, deal, payments, history) in a NEW
// tab, so the resolver keeps the pending queue. Gated on clients.view — the
// project page's own permission.
function openProjectPage() {
  const href = router.resolve({
    name: 'clients.project',
    params: { id: props.clientId, projectId: props.project.id },
  }).href
  window.open(href, '_blank', 'noopener')
}

const open = ref(false)
const loading = ref(false)
const error = ref(null)
const data = ref(null)

const money = (v) => (v == null ? null : Number(v).toLocaleString())

async function toggle() {
  open.value = !open.value
  if (open.value && !data.value && !loading.value) {
    loading.value = true
    error.value = null
    try {
      data.value = await duplicateRequestsApi.previewProject(props.reqId, props.project.id)
    } catch (e) {
      error.value = e.response?.data?.message ?? t('oversight.projectDetailsFailed')
    } finally {
      loading.value = false
    }
  }
}
</script>

<template>
  <li class="rounded-lg border border-line">
    <div class="flex flex-wrap items-center gap-2 p-2.5">
      <span class="rounded-full border border-line px-2 py-0.5 text-xs text-ink">
        {{ humanize(project.step) }}
      </span>
      <span class="text-sm text-ink">
        {{ project.location ?? 'Project' }} <span class="num text-mute">#{{ project.id }}</span>
      </span>

      <div class="ms-auto flex flex-wrap gap-2">
        <Button
          v-if="auth.can('clients.view')"
:label="$t('common.open')"
          icon="pi pi-external-link"
          size="small"
          severity="secondary"
          outlined
          @click="openProjectPage"
        />
        <Button
          :label="open ? 'Hide' : 'Inspect'"
          :icon="open ? 'pi pi-chevron-up' : 'pi pi-search'"
          size="small"
          text
          @click="toggle"
        />
        <Button
:label="$t('project.share')"
          icon="pi pi-share-alt"
          size="small"
          :loading="busy"
          @click="$emit('share')"
        />
        <Button
:label="$t('oversight.startSeparate')"
          icon="pi pi-clone"
          size="small"
          severity="secondary"
          :loading="busy"
          @click="$emit('fork')"
        />
      </div>
    </div>

    <!-- Lazy inspection panel. -->
    <div v-if="open" class="border-t border-line bg-ground p-3 text-sm">
      <p v-if="loading" class="text-mute">Loading details…</p>
      <p v-else-if="error" class="text-danger">{{ error }}</p>
      <template v-else-if="data">
        <div class="grid gap-x-6 gap-y-1 sm:grid-cols-2">
          <p class="text-mute">
            Opened by <span class="text-ink">{{ data.opened_by ?? '—' }}</span>
            · {{ formatDateTime(data.opened_at) }}
          </p>
          <p class="text-mute">
            Contributors: <span class="num text-ink">{{ data.contributor_count }}</span>
          </p>
          <p v-if="data.unit" class="text-mute">
            Unit: <span class="num text-ink">{{ data.unit }}</span>
          </p>
          <p v-if="data.active_deal_state" class="text-mute">
            Open deal: <span class="text-ink">{{ humanize(data.active_deal_state) }}</span>
          </p>
        </div>

        <p class="mt-2 text-xs uppercase tracking-wide text-mute">Activity</p>
        <p class="text-ink">
          <span class="num">{{ data.counts.calls }}</span> calls ·
          <span class="num">{{ data.counts.visits }}</span> visits ·
          <span class="num">{{ data.counts.shortlist }}</span> shortlisted ·
          <span class="num">{{ data.counts.deals }}</span> deals
        </p>

        <template v-if="data.desire">
          <p class="mt-2 text-xs uppercase tracking-wide text-mute">The brief</p>
          <p class="text-ink">
            <span v-if="data.desire.type">{{ data.desire.type }}</span>
            <span v-if="data.desire.rooms"> · {{ data.desire.rooms }}</span>
            <span v-if="data.desire.wilaya">
              · {{ data.desire.wilaya }}<span v-if="data.desire.commune">, {{ data.desire.commune }}</span>
            </span>
          </p>
          <p v-if="data.desire.budget_min || data.desire.budget_max" class="text-mute">
            Budget:
            <span class="num text-ink">{{ money(data.desire.budget_min) ?? '…' }}</span>
            – <span class="num text-ink">{{ money(data.desire.budget_max) ?? '…' }}</span>
          </p>
          <p v-if="data.desire.area_min || data.desire.area_max" class="text-mute">
            Area:
            <span class="num text-ink">{{ data.desire.area_min ?? '…' }}</span>
            – <span class="num text-ink">{{ data.desire.area_max ?? '…' }}</span> m²
          </p>
          <p v-if="data.desire.notes" class="mt-1 whitespace-pre-line text-ink">{{ data.desire.notes }}</p>
        </template>
      </template>
    </div>
  </li>
</template>
