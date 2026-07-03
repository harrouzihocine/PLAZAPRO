<script setup>
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import BaseSelect from '@/components/base/BaseSelect.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import { projectViewersApi, staffApi } from '@/features/clients/api'
import { useAuthStore } from '@/features/settings/store'
import { toastError } from '@/composables/useConfirm'

// "Who can see this project": the creator + the colleagues it was shared with.
// Hiding keeps the row (greyed, re-showable) — access history is never erased.
// Adding / hiding needs the projects.contributors permission.
const props = defineProps({ projectId: { type: [String, Number], required: true } })

const auth = useAuthStore()
const canShare = computed(() => auth.can('projects.contributors'))

const viewers = ref([])
const staff = ref([])
const adding = ref('')
const busy = ref(false)

onMounted(async () => {
  try {
    viewers.value = await projectViewersApi.list(props.projectId)
    if (canShare.value) staff.value = await staffApi.list()
  } catch {
    /* the card simply stays empty if the list can't load */
  }
})

// Only colleagues not already on the list (hidden ones re-appear via "Show").
const candidates = computed(() => {
  const listed = new Set(viewers.value.map((v) => v.id))
  return staff.value.filter((u) => !listed.has(u.id))
})

async function run(fn) {
  busy.value = true
  try {
    viewers.value = await fn()
  } catch (e) {
    toastError(e.response?.data?.message ?? 'Action failed.')
  } finally {
    busy.value = false
  }
}

async function addViewer() {
  if (!adding.value) return
  await run(() => projectViewersApi.add(props.projectId, adding.value))
  adding.value = ''
}

const hideViewer = (user) => run(() => projectViewersApi.hide(props.projectId, user.id))
const showViewer = (user) => run(() => projectViewersApi.add(props.projectId, user.id))
</script>

<template>
  <SectionCard title="Who can see this project" icon="pi pi-eye">
    <ul class="space-y-1.5 text-sm">
      <li
        v-for="v in viewers"
        :key="v.id"
        class="flex items-center justify-between gap-2 rounded-lg px-2 py-1.5"
        :class="v.hidden ? 'opacity-50' : ''"
      >
        <span class="flex min-w-0 items-center gap-2">
          <i class="pi pi-user shrink-0 text-mute" aria-hidden="true" />
          <span class="truncate text-ink">{{ v.name }}</span>
          <Tag v-if="v.is_creator" value="creator" severity="secondary" />
          <Tag v-else-if="v.hidden" value="hidden" severity="warn" />
        </span>
        <template v-if="canShare && !v.is_creator">
          <Button
            v-if="!v.hidden"
            icon="pi pi-eye-slash"
            text
            rounded
            size="small"
            severity="secondary"
            :disabled="busy"
            aria-label="Hide from this user"
            v-tooltip.top="'Hide (kept, not removed)'"
            @click="hideViewer(v)"
          />
          <Button
            v-else
            icon="pi pi-eye"
            text
            rounded
            size="small"
            :disabled="busy"
            aria-label="Show to this user again"
            v-tooltip.top="'Show again'"
            @click="showViewer(v)"
          />
        </template>
      </li>
      <li v-if="!viewers.length" class="px-2 py-1.5 text-mute">
        Only holders of the view-all-projects permission see it.
      </li>
    </ul>

    <!-- Share with a colleague -->
    <div v-if="canShare" class="mt-3 flex items-end gap-2 border-t border-line pt-3">
      <BaseSelect
        v-model="adding"
        label="Add a colleague"
        placeholder="Pick a user…"
        class="min-w-0 flex-1"
        :options="candidates.map((u) => ({ value: u.id, label: u.name }))"
      />
      <Button
        icon="pi pi-plus"
        label="Add"
        size="small"
        :disabled="busy || !adding"
        @click="addViewer"
      />
    </div>
  </SectionCard>
</template>
