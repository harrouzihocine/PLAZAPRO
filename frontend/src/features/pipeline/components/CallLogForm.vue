<script setup>
import { computed, ref } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'
import { useDynamicList } from '@/composables/useDynamicList'
import ProjectUnitsPicker from '@/features/inventory/components/ProjectUnitsPicker.vue'
import DesireFields from '@/features/clients/components/DesireFields.vue'
import NextActionFields from '@/features/pipeline/components/NextActionFields.vue'

// The fast-entry call log (qualification happens on the phone): direction
// defaults to outbound, what-was-discussed is one tap-chip list (the former
// separate "outcome" reasons live in it too), free-text notes are a textarea.
// Two qualification branches can be filled inline:
//  - "Select properties" (Branch B): ProjectUnitsPicker → project shortlist;
//  - "No match — requirements" (Branch A): DesireFields → desire profile.
// Ends with the enforced next action. Emits the ready payload; the parent submits.
const props = defineProps({
  client: { type: Object, default: null }, // for the interest chips
  fieldAgents: { type: Array, default: () => [] },
  saving: { type: Boolean, default: false },
  desire: { type: Object, default: null }, // saved desire, prefills Branch A
})
const emit = defineEmits(['submit', 'cancel'])

const { items: callTopics } = useDynamicList('call_topics')
const { items: propertyInterests } = useDynamicList('property_interests')

const direction = ref('outbound')
const notes = ref('')
const topics = ref([])
const nextAction = ref({ type: 'call', due_date: '', due_time: '', assigned_to: '' })

// Qualification branch: null (quick call) | 'properties' | 'desire'.
const branch = ref(null)
const properties = ref([])
const desireForm = ref({
  wilaya_id: props.desire?.wilaya_id ?? '',
  commune_id: props.desire?.commune_id ?? '',
  type_id: props.desire?.type_id ?? '',
  budget_min: props.desire?.budget_min ?? '',
  budget_max: props.desire?.budget_max ?? '',
  floor_pref: props.desire?.floor_pref ?? '',
  notes: props.desire?.notes ?? '',
})

// The client's interest categories (captured at lead creation) as passive context.
const interestLabels = computed(() => {
  const ids = new Set(props.client?.interests ?? [])
  return propertyInterests.value.filter((i) => ids.has(i.id)).map((i) => i.label)
})

function toggleTopic(id) {
  const i = topics.value.indexOf(id)
  if (i === -1) topics.value.push(id)
  else topics.value.splice(i, 1)
}

const nextActionReady = computed(
  () =>
    !!nextAction.value.due_date &&
    (nextAction.value.type !== 'in_site_visit' || !!nextAction.value.assigned_to),
)

function submit() {
  if (!nextActionReady.value) return
  const payload = {
    direction: direction.value,
    notes: notes.value.trim() || null,
    topics: topics.value,
    next_action: { ...nextAction.value },
  }
  if (branch.value === 'properties' && properties.value.length) {
    payload.properties = properties.value.map(({ shortlistable_type, shortlistable_id }) => ({
      shortlistable_type,
      shortlistable_id,
    }))
  }
  if (branch.value === 'desire') {
    payload.desire = {
      wilaya_id: desireForm.value.wilaya_id || null,
      commune_id: desireForm.value.commune_id || null,
      type_id: desireForm.value.type_id || null,
      budget_min: desireForm.value.budget_min === '' ? null : Number(desireForm.value.budget_min),
      budget_max: desireForm.value.budget_max === '' ? null : Number(desireForm.value.budget_max),
      floor_pref: (desireForm.value.floor_pref ?? '').trim() || null,
      notes: (desireForm.value.notes ?? '').trim() || null,
    }
  }
  emit('submit', payload)
}
</script>

<template>
  <form class="space-y-4" @submit.prevent="submit">
    <BaseSelect
      v-model="direction"
      label="Direction"
      class="sm:max-w-xs"
      :clearable="false"
      :options="[
        { value: 'outbound', label: 'Outbound' },
        { value: 'inbound', label: 'Inbound' },
      ]"
    />

    <!-- Fast talking-points — everything discussed / how the call went, in taps. -->
    <fieldset v-if="callTopics.length" class="rounded-xl border border-line p-3">
      <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-mute">
        Discussed
      </legend>
      <div class="flex flex-wrap gap-1.5">
        <button
          v-for="t in callTopics"
          :key="t.id"
          type="button"
          class="rounded-full border px-3 py-1.5 text-xs transition-colors"
          :class="
            topics.includes(t.id)
              ? 'border-primary bg-highlight font-medium text-ink'
              : 'border-line text-mute hover:border-primary hover:text-ink'
          "
          @click="toggleTopic(t.id)"
        >
          {{ t.label }}
        </button>
      </div>
    </fieldset>

    <BaseTextarea v-model="notes" label="Notes (optional)" :rows="3" />

    <!-- Qualification: properties (Branch B) or desire profile (Branch A). -->
    <fieldset class="rounded-xl border border-line p-3">
      <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-mute">
        Client interests<template v-if="interestLabels.length">
          — {{ interestLabels.join(' · ') }}</template
        >
      </legend>
      <div class="mb-3 flex flex-wrap gap-1.5">
        <button
          type="button"
          class="rounded-full border px-3 py-1.5 text-xs transition-colors"
          :class="
            branch === 'properties'
              ? 'border-primary bg-highlight font-medium text-ink'
              : 'border-line text-mute hover:border-primary hover:text-ink'
          "
          @click="branch = branch === 'properties' ? null : 'properties'"
        >
          <i class="pi pi-building text-[10px]" aria-hidden="true" /> Select matching properties
        </button>
        <button
          type="button"
          class="rounded-full border px-3 py-1.5 text-xs transition-colors"
          :class="
            branch === 'desire'
              ? 'border-primary bg-highlight font-medium text-ink'
              : 'border-line text-mute hover:border-primary hover:text-ink'
          "
          @click="branch = branch === 'desire' ? null : 'desire'"
        >
          <i class="pi pi-heart text-[10px]" aria-hidden="true" /> No match — capture requirements
        </button>
      </div>
      <ProjectUnitsPicker v-if="branch === 'properties'" v-model="properties" />
      <DesireFields v-if="branch === 'desire'" v-model="desireForm" />
    </fieldset>

    <NextActionFields v-model="nextAction" :field-agents="fieldAgents" />

    <div class="flex gap-2 pt-1">
      <BaseButton type="submit" :disabled="saving || !nextActionReady">Save call</BaseButton>
      <BaseButton type="button" variant="ghost" @click="emit('cancel')">Cancel</BaseButton>
    </div>
  </form>
</template>
