<script setup>
import { computed, ref } from 'vue'
import Checkbox from 'primevue/checkbox'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'
import { useDynamicList } from '@/composables/useDynamicList'
import ProjectUnitsPicker from '@/features/inventory/components/ProjectUnitsPicker.vue'
import DesireFields from '@/features/clients/components/DesireFields.vue'
import { desireForm as makeDesireForm, desirePayload } from '@/features/clients/desire'
import DraftBanner from '@/features/drafts/DraftBanner.vue'
import NextActionFields from '@/features/pipeline/components/NextActionFields.vue'
import { useModalDraft } from '@/composables/useModalDraft'

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
  draftKey: { type: String, default: null }, // draft-protects the modal when set
})
const emit = defineEmits(['submit', 'cancel'])

const { items: callTopics } = useDynamicList('call_topics')
const { items: propertyInterests } = useDynamicList('property_interests')

const direction = ref('outbound')
const notes = ref('')
const topics = ref([])
// Optional plan: most calls leave a next step (default ON), but some genuinely
// end a thread — untick and the call is logged without one (addable later).
const planNext = ref(true)
const nextAction = ref({ type: 'call', due_date: '', due_time: '', assigned_to: '' })

// Qualification branch: null (quick call) | 'properties' | 'desire'.
const branch = ref(null)
const properties = ref([])
const desireForm = ref(makeDesireForm(props.desire))

// Draft protection: a misclick outside the modal keeps everything typed here;
// reopening (or the top-bar pencil) restores it.
const draft = props.draftKey
  ? useModalDraft({
      key: props.draftKey,
      label: `Call log — ${props.client?.full_name ?? 'client'}`,
      getForm: () => ({
        direction: direction.value,
        notes: notes.value,
        topics: topics.value,
        planNext: planNext.value,
        nextAction: nextAction.value,
        branch: branch.value,
        properties: properties.value,
        desireForm: desireForm.value,
      }),
      setForm: (d) => {
        direction.value = d.direction ?? 'outbound'
        notes.value = d.notes ?? ''
        topics.value = d.topics ?? []
        planNext.value = d.planNext ?? true
        nextAction.value = d.nextAction ?? { type: 'call', due_date: '', due_time: '', assigned_to: '' }
        branch.value = d.branch ?? null
        properties.value = d.properties ?? []
        desireForm.value = d.desireForm ?? makeDesireForm(props.desire)
      },
    })
  : null

function cancel() {
  draft?.discard()
  emit('cancel')
}

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

// In-site plans may go out unassigned — the dispatch board picks the agent.
const nextActionReady = computed(() => !planNext.value || !!nextAction.value.due_date)

// Branch A saves a desire — its notes are required (the story behind the numbers).
const desireReady = computed(
  () => branch.value !== 'desire' || !!(desireForm.value.notes ?? '').trim(),
)

function submit() {
  if (!nextActionReady.value || !desireReady.value) return
  const payload = {
    direction: direction.value,
    notes: notes.value.trim() || null,
    topics: topics.value,
  }
  if (planNext.value) payload.next_action = { ...nextAction.value }
  if (branch.value === 'properties' && properties.value.length) {
    payload.properties = properties.value.map(({ shortlistable_type, shortlistable_id }) => ({
      shortlistable_type,
      shortlistable_id,
    }))
  }
  if (branch.value === 'desire') {
    payload.desire = desirePayload(desireForm.value)
  }
  draft?.complete()
  emit('submit', payload)
}
</script>

<template>
  <form class="space-y-4" @submit.prevent="submit">
    <DraftBanner :visible="!!draft?.restored.value" @discard="draft.discard()" />
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

    <!-- Optional next step: unticked, the call closes its thread (a plan can
         still be added later from the timeline). -->
    <label class="flex w-fit cursor-pointer items-center gap-2 text-sm font-medium text-ink">
      <Checkbox v-model="planNext" binary />
      Plan a next action
    </label>
    <NextActionFields v-if="planNext" v-model="nextAction" :field-agents="fieldAgents" />

    <div class="flex gap-2 pt-1">
      <BaseButton type="submit" :disabled="saving || !nextActionReady || !desireReady">
        Save call
      </BaseButton>
      <BaseButton type="button" variant="ghost" @click="cancel">Cancel</BaseButton>
    </div>
  </form>
</template>
