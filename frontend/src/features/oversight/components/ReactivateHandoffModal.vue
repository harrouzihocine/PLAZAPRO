<script setup>
// Reactivate an archived project AND hand it to a chosen team — the archive
// mirror of the duplicate resolver. Shows who opened the project and who works
// it now, lets the manager pick who should handle it, and — only when that set
// changes — choose "as-is" (same project, reassigned) or "separate" (a new
// siloed project for them, original stays archived). Selected users are notified.
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import BaseModal from '@/components/base/BaseModal.vue'
import BaseMultiSelect from '@/components/base/BaseMultiSelect.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import { projectsApi, projectHandlersApi } from '@/features/clients/api'
import { toastError, toastSuccess } from '@/composables/useConfirm'

const props = defineProps({
  // The archive row: { id, client_id, client }.
  project: { type: Object, required: true },
})
const emit = defineEmits(['close', 'done'])

const loading = ref(true)
const busy = ref(false)
const preview = ref({ opened_by: null, contributors: [], in_site_agents: [] })
const handlers = ref([])

// The team besides the opener (who always keeps access). Pre-set to the current
// non-creator contributors.
const selected = ref([])
const mode = ref('in_place')
const primaryId = ref('')

onMounted(async () => {
  try {
    const [pv, hs] = await Promise.all([
      projectsApi.handoffPreview(props.project.id),
      projectHandlersApi.list(),
    ])
    preview.value = pv
    handlers.value = hs
    selected.value = currentViewerIds.value
  } catch (e) {
    toastError(e.response?.data?.message ?? 'Could not load the project team.')
    emit('close')
  } finally {
    loading.value = false
  }
})

const openerId = computed(() => preview.value.opened_by?.id ?? null)
const currentViewerIds = computed(() =>
  preview.value.contributors.filter((c) => !c.is_creator).map((c) => c.id),
)

// The picker pool: everyone who can hold a project, plus any current contributor
// (so they still show even if they no longer hold projects.create). The opener
// is excluded — they are shown separately and always kept.
const handlerOptions = computed(() => {
  const map = new Map()
  for (const c of preview.value.contributors) if (c.id !== openerId.value) map.set(c.id, c.name)
  for (const h of handlers.value) if (h.id !== openerId.value) map.set(h.id, h.name)
  return [...map].map(([value, label]) => ({ value, label }))
})

function sameSet(a, b) {
  if (a.length !== b.length) return false
  const s = new Set(a)
  return b.every((x) => s.has(x))
}
const changed = computed(() => !sameSet(selected.value, currentViewerIds.value))

// handler_ids sent to the server = the opener (locked) + the chosen team.
const handlerIds = computed(() =>
  [...new Set([openerId.value, ...selected.value].filter((id) => id != null))],
)

// Primary-owner options (separate mode): the whole team by name.
const primaryOptions = computed(() =>
  handlerIds.value.map((id) => ({
    value: id,
    label:
      id === openerId.value
        ? `${preview.value.opened_by?.name} (opener)`
        : (handlerOptions.value.find((o) => o.value === id)?.label ?? `#${id}`),
  })),
)

// Keep a valid primary as the selection changes; default to the first new member.
const effectivePrimary = computed(() => {
  if (primaryId.value && handlerIds.value.includes(primaryId.value)) return primaryId.value
  return selected.value[0] ?? openerId.value
})

const submitLabel = computed(() => {
  if (!changed.value) return 'Reactivate'
  return mode.value === 'separate' ? 'Create separate project' : 'Reactivate & reassign'
})

async function submit() {
  busy.value = true
  try {
    const payload = changed.value
      ? {
          handler_ids: handlerIds.value,
          mode: mode.value,
          ...(mode.value === 'separate' ? { primary_id: effectivePrimary.value } : {}),
        }
      : {}
    await projectsApi.reactivate(props.project.id, payload)
    toastSuccess(
      changed.value && mode.value === 'separate'
        ? 'Separate project created for the team.'
        : 'Project reactivated.',
    )
    emit('done')
  } catch (e) {
    toastError(e.response?.data?.message ?? 'Could not reactivate the project.')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <BaseModal title="Reactivate project" size="max-w-xl" @close="emit('close')">
    <p v-if="project.client" class="-mt-1 mb-4 text-sm text-mute">
      Client: <span class="font-medium text-ink">{{ project.client }}</span>
    </p>

    <p v-if="loading" class="py-6 text-center text-sm text-mute">Loading…</p>

    <div v-else class="space-y-4">
      <!-- Who is behind it today -->
      <div class="rounded-lg border border-line bg-surface-50 px-4 py-3 dark:bg-surface-900">
        <p class="text-sm text-ink">
          <i class="pi pi-user mr-1.5 text-mute" aria-hidden="true" />
          Opened by
          <span class="font-semibold">{{ preview.opened_by?.name ?? '—' }}</span>
        </p>
        <p class="mt-0.5 text-xs text-mute">The opener always keeps access to what they created.</p>

        <div v-if="preview.contributors.length" class="mt-3">
          <p class="mb-1 text-xs font-medium text-mute">Contributors now</p>
          <div class="flex flex-wrap gap-1.5">
            <span
              v-for="c in preview.contributors"
              :key="c.id"
              class="rounded-full bg-highlight px-2.5 py-1 text-xs text-ink"
            >
              {{ c.name }}<span v-if="c.is_creator" class="text-mute"> · opener</span>
            </span>
          </div>
        </div>

        <div v-if="preview.in_site_agents.length" class="mt-3">
          <p class="mb-1 text-xs font-medium text-mute">In-site agents</p>
          <div class="flex flex-wrap gap-1.5">
            <span
              v-for="a in preview.in_site_agents"
              :key="a.id"
              class="rounded-full bg-highlight px-2.5 py-1 text-xs text-ink"
            >
              {{ a.name }}
            </span>
          </div>
        </div>
      </div>

      <!-- Who should handle it now -->
      <BaseMultiSelect
        v-model="selected"
        label="Hand it to"
        placeholder="Keep the current team"
        :options="handlerOptions"
      />
      <p class="-mt-2 text-xs text-mute">
        Only users allowed to open a client project are listed. Leave unchanged to simply reactivate.
      </p>

      <!-- How to hand it over — only when the team changed -->
      <div v-if="changed" class="space-y-2">
        <p class="text-sm font-medium text-ink">How should they get it?</p>
        <label
          class="flex cursor-pointer gap-3 rounded-lg border px-3 py-2.5 text-sm"
          :class="mode === 'in_place' ? 'border-primary bg-highlight' : 'border-line'"
        >
          <input v-model="mode" type="radio" value="in_place" class="mt-1" />
          <span>
            <span class="font-medium text-ink">Reactivate as-is</span>
            <span class="block text-xs text-mute">
              Same project — the selected people replace the current contributors (the opener stays).
            </span>
          </span>
        </label>
        <label
          class="flex cursor-pointer gap-3 rounded-lg border px-3 py-2.5 text-sm"
          :class="mode === 'separate' ? 'border-primary bg-highlight' : 'border-line'"
        >
          <input v-model="mode" type="radio" value="separate" class="mt-1" />
          <span>
            <span class="font-medium text-ink">Create a separate project</span>
            <span class="block text-xs text-mute">
              A new siloed project for the team on this client. This one stays archived as history.
            </span>
          </span>
        </label>

        <BaseSelect
          v-if="mode === 'separate'"
          v-model="primaryId"
          label="Primary owner of the new project"
          :options="primaryOptions"
          :placeholder="primaryOptions.find((o) => o.value === effectivePrimary)?.label"
        />
      </div>

      <div class="flex justify-end gap-2 pt-2">
        <Button label="Cancel" text severity="secondary" :disabled="busy" @click="emit('close')" />
        <Button :label="submitLabel" icon="pi pi-undo" :loading="busy" @click="submit" />
      </div>
    </div>
  </BaseModal>
</template>
