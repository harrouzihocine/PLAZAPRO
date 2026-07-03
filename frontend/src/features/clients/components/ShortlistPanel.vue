<script setup>
import { computed, onMounted, ref } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import { toastError } from '@/composables/useConfirm'
import { shortlistApi } from '@/features/clients/api'
import ProjectUnitsPicker from '@/features/inventory/components/ProjectUnitsPicker.vue'
import { useAuthStore } from '@/features/settings/store'

// The project's property shortlist — the units (apartments / locals) and boxes
// the client wants to see. Each row shows the FULL property card and its journey
// state (shortlisted → visited → won/lost). Closure happens on the DEAL (the
// interested ones enter it at the visit log); this panel curates the list.
const props = defineProps({ projectId: { type: [String, Number], required: true } })
const emit = defineEmits(['changed'])
const auth = useAuthStore()
const canEdit = () => auth.can('visits.conduct')

const items = ref([]) // working copy: { shortlistable_type, shortlistable_id, state, property }
const additions = ref([]) // ProjectUnitsPicker v-model: properties to add on save
const saving = ref(false)

const stateClass = {
  shortlisted: 'bg-border text-ink',
  not_visited: 'bg-warning/15 text-warning',
  visited_interested: 'bg-success/15 text-success',
  visited_not_interested: 'bg-danger/15 text-danger',
  won: 'bg-success/15 text-success',
  lost: 'bg-danger/15 text-danger',
}

// The full property card, not just the code.
const propertyLine = (it) => {
  const p = it.property
  if (!p) return `${it.shortlistable_type} #${it.shortlistable_id}`
  return [p.reference, p.property_type, p.floor, p.area_sqm ? `${p.area_sqm} m²` : null, p.price, p.location]
    .filter(Boolean)
    .join(' · ')
}

// Keys already on the shortlist — hidden inside the picker.
const excludeKeys = computed(() =>
  items.value.map((i) => `${i.shortlistable_type}:${i.shortlistable_id}`),
)
const hasChanges = computed(() => additions.value.length > 0)

onMounted(load)

async function load() {
  const list = await shortlistApi.list(props.projectId)
  items.value = list.map((i) => ({
    id: i.id,
    shortlistable_type: i.shortlistable_type,
    shortlistable_id: i.shortlistable_id,
    state: i.state,
    property: i.property,
  }))
  additions.value = []
}

function remove(item) {
  const i = items.value.indexOf(item)
  if (i !== -1) items.value.splice(i, 1)
}

async function save() {
  const all = [...items.value, ...additions.value]
  if (!all.length) {
    toastError('Add at least one property to the shortlist.')
    return
  }
  saving.value = true
  try {
    await shortlistApi.sync(
      props.projectId,
      all.map((i) => ({ shortlistable_type: i.shortlistable_type, shortlistable_id: i.shortlistable_id })),
    )
    await load()
    emit('changed')
  } catch (e) {
    toastError(e.response?.data?.message ?? 'Could not save the shortlist.')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="rounded-token border border-border p-3">
    <h3 class="mb-2 text-sm font-semibold uppercase opacity-60">Property shortlist</h3>

    <ul v-if="items.length" class="mb-2 space-y-1">
      <li
        v-for="it in items"
        :key="it.shortlistable_type + it.shortlistable_id"
        class="flex items-center justify-between gap-2 text-sm"
      >
        <span>
          {{ it.shortlistable_type === 'box' ? '🅿' : '🏠' }}
          {{ propertyLine(it) }}
          <span class="ml-1 rounded-token px-1.5 py-0.5 text-xs" :class="stateClass[it.state] ?? 'bg-border text-ink'">
            {{ it.state.replace(/_/g, ' ') }}
          </span>
        </span>
        <button
          v-if="canEdit() && !['won', 'lost'].includes(it.state)"
          type="button"
          class="opacity-60 hover:text-danger"
          title="Remove"
          @click="remove(it)"
        >
          ✕
        </button>
      </li>
    </ul>
    <p v-else class="mb-2 text-sm opacity-60">No properties shortlisted yet.</p>

    <div v-if="canEdit()" class="space-y-2">
      <ProjectUnitsPicker v-model="additions" :exclude="excludeKeys" />
      <BaseButton type="button" :disabled="saving || (!hasChanges && !items.length)" @click="save">
        Save shortlist
      </BaseButton>
    </div>
  </div>
</template>
