<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'
import { useDynamicList } from '@/composables/useDynamicList'
import { toastError } from '@/composables/useConfirm'
import { shortlistApi } from '@/features/clients/api'
import { boxesApi } from '@/features/inventory/api'
import ProjectUnitsPicker from '@/features/inventory/components/ProjectUnitsPicker.vue'
import NextActionFields from '@/features/pipeline/components/NextActionFields.vue'

// The rapid visit-completion log.
//  - OFFICE visits: outcome select + what-happened tap-chips + the deal's property
//    shortlist manager (add via ProjectUnitsPicker / remove; at least one must
//    remain — the server enforces the same rule);
//  - IN-SITE visits: one-tap result chips (didn't visit / interested / …).
// Both take free-text notes (textarea) and end with the enforced next action.
//
// Deal step: when the client is interested, the agent can open THE deal right
// here with the selected properties (only interested ones) — each unit optionally
// takes 1..max-available boxes. Emits { ...completion, deal? }; parent submits.
const props = defineProps({
  visit: { type: Object, required: true },
  fieldAgents: { type: Array, default: () => [] },
  saving: { type: Boolean, default: false },
  canDeal: { type: Boolean, default: false }, // user may open a deal (visits.conduct)
})
const emit = defineEmits(['submit', 'cancel'])

const { items: officeOutcomes } = useDynamicList('visit_outcomes')
const { items: insiteOutcomes } = useDynamicList('insite_outcomes')
const { items: officeChecklist } = useDynamicList('office_visit_checklist')

const isOffice = computed(() => props.visit.type === 'office')
const hasDeal = computed(() => !!props.visit.client_project_id)

const outcomeId = ref('')
const notes = ref('')
const checklist = ref([])
const nextAction = ref({ type: 'call', due_date: '', due_time: '', assigned_to: '' })

// Office shortlist manager state: the deal's current active list, editable.
const shortlist = ref([]) // [{ shortlistable_type, shortlistable_id, label, state, property }]
const additions = ref([]) // ProjectUnitsPicker v-model

// --- Open-the-deal step (only interested properties enter the deal) ---
const makeDeal = ref(false)
const dealUnits = ref([]) // [{ unit_id, label, location_id, include: bool, box_count }]
const availableBoxes = ref({}) // location_id -> count

const propertyLabel = (p) =>
  p
    ? [p.reference, p.property_type, p.floor, p.area_sqm ? `${p.area_sqm} m²` : null, p.price]
        .filter(Boolean)
        .join(' · ')
    : null

onMounted(async () => {
  if (isOffice.value && hasDeal.value) {
    const items = await shortlistApi.list(props.visit.client_project_id)
    shortlist.value = items
      .filter((i) => !['won', 'lost'].includes(i.state))
      .map((i) => ({
        shortlistable_type: i.shortlistable_type,
        shortlistable_id: i.shortlistable_id,
        label: propertyLabel(i.property) ?? `${i.shortlistable_type} #${i.shortlistable_id}`,
        state: i.state,
        location_id: i.property?.location_id ?? null,
      }))
  }
})

async function loadBoxAvailability(locationId) {
  if (!locationId || availableBoxes.value[locationId] !== undefined) return
  const boxes = await boxesApi.list({ location_id: locationId, sale_status: 'available' })
  availableBoxes.value = { ...availableBoxes.value, [locationId]: boxes.length }
}

// The units eligible for the deal: in-site → the visited unit; office → the
// units on the final shortlist (client-rejected ones are not offered).
const selectedInsiteOutcome = computed(() =>
  insiteOutcomes.value.find((o) => o.id === outcomeId.value),
)
const insiteInterested = computed(() => selectedInsiteOutcome.value?.value === 'visited_interested')

const eligibleDealUnits = computed(() => {
  if (!props.canDeal || !hasDeal.value) return []
  if (isOffice.value) {
    return [...shortlist.value, ...additions.value]
      .filter((i) => i.shortlistable_type === 'unit' && i.state !== 'visited_not_interested')
      .map((i) => ({ unit_id: i.shortlistable_id, label: i.label, location_id: i.location_id }))
  }
  if (!insiteInterested.value || !props.visit.unit) return []
  return [
    {
      unit_id: props.visit.unit.id,
      label: propertyLabel(props.visit.unit) ?? props.visit.unit.reference,
      location_id: props.visit.unit.location_id ?? null,
    },
  ]
})

// Keep the deal-unit working list in sync with what's eligible.
watch(eligibleDealUnits, (units) => {
  dealUnits.value = units.map((u) => {
    const prev = dealUnits.value.find((d) => d.unit_id === u.unit_id)
    return { ...u, include: prev?.include ?? !isOffice.value, box_count: prev?.box_count ?? 0 }
  })
  units.forEach((u) => loadBoxAvailability(u.location_id))
})

const includedDealUnits = computed(() => dealUnits.value.filter((u) => u.include))

function toggleChecklist(id) {
  const i = checklist.value.indexOf(id)
  if (i === -1) checklist.value.push(id)
  else checklist.value.splice(i, 1)
}

const excludeKeys = computed(() =>
  shortlist.value.map((i) => `${i.shortlistable_type}:${i.shortlistable_id}`),
)
const finalShortlist = computed(() => [...shortlist.value, ...additions.value])

const nextActionReady = computed(
  () =>
    !!nextAction.value.due_date &&
    (nextAction.value.type !== 'in_site_visit' || !!nextAction.value.assigned_to),
)

function submit() {
  if (!nextActionReady.value) return
  if (isOffice.value && hasDeal.value && !finalShortlist.value.length) {
    toastError('At least one property must remain shortlisted.')
    return
  }
  if (makeDeal.value && !includedDealUnits.value.length) {
    toastError('Select at least one property for the deal (or untick "open a deal").')
    return
  }
  const payload = {
    outcome_id: outcomeId.value || null,
    notes: notes.value.trim() || null,
    checklist: checklist.value,
    next_action: { ...nextAction.value },
  }
  if (isOffice.value && hasDeal.value) {
    payload.shortlist = finalShortlist.value.map(({ shortlistable_type, shortlistable_id }) => ({
      shortlistable_type,
      shortlistable_id,
    }))
  }
  if (makeDeal.value && includedDealUnits.value.length) {
    payload.deal = {
      visit_id: props.visit.id,
      units: includedDealUnits.value.map((u) => ({ unit_id: u.unit_id, box_count: u.box_count })),
    }
  }
  emit('submit', payload)
}
</script>

<template>
  <form class="space-y-2" @submit.prevent="submit">
    <!-- In-site: one-tap result (didn't visit / interested / not interested / …). -->
    <fieldset v-if="!isOffice" class="rounded-token border border-border p-2">
      <legend class="px-1 text-xs uppercase opacity-60">Result</legend>
      <div class="flex flex-wrap gap-1.5">
        <button
          v-for="o in insiteOutcomes"
          :key="o.id"
          type="button"
          class="rounded-token border px-3 py-1.5 text-sm transition-colors"
          :class="outcomeId === o.id ? 'border-primary bg-primary/15 text-ink' : 'border-border bg-bg opacity-80 hover:border-primary'"
          @click="outcomeId = outcomeId === o.id ? '' : o.id"
        >
          {{ o.label }}
        </button>
      </div>
    </fieldset>

    <template v-else>
      <BaseSelect
        v-model="outcomeId"
        label="Outcome"
        placeholder="None"
        :options="officeOutcomes.map((o) => ({ value: o.id, label: o.label }))"
      />

      <!-- Office-visit checklist — what happened, logged in taps. -->
      <fieldset v-if="officeChecklist.length" class="rounded-token border border-border p-2">
        <legend class="px-1 text-xs uppercase opacity-60">What happened</legend>
        <div class="flex flex-wrap gap-1.5">
          <button
            v-for="c in officeChecklist"
            :key="c.id"
            type="button"
            class="rounded-token border px-2 py-1 text-xs transition-colors"
            :class="checklist.includes(c.id) ? 'border-primary bg-primary/15 text-ink' : 'border-border bg-bg opacity-80 hover:border-primary'"
            @click="toggleChecklist(c.id)"
          >
            {{ c.label }}
          </button>
        </div>
      </fieldset>

      <!-- The deal's property shortlist: keep / drop / add. ≥1 must remain. -->
      <fieldset v-if="hasDeal" class="rounded-token border border-border p-2">
        <legend class="px-1 text-xs uppercase opacity-60">Property shortlist (at least one)</legend>
        <div v-if="shortlist.length" class="mb-2 flex flex-wrap gap-1.5">
          <span
            v-for="(it, i) in shortlist"
            :key="it.shortlistable_type + it.shortlistable_id"
            class="inline-flex items-center gap-1 rounded-token bg-primary/10 px-2 py-1 text-xs"
          >
            {{ it.label }}
            <button type="button" class="opacity-60 hover:text-danger" @click="shortlist.splice(i, 1)">✕</button>
          </span>
        </div>
        <ProjectUnitsPicker v-model="additions" :exclude="excludeKeys" />
      </fieldset>
    </template>

    <BaseTextarea v-model="notes" label="Notes (optional)" :rows="3" />

    <!-- Open the deal with the interested properties (auto-reserves them). -->
    <fieldset
      v-if="canDeal && eligibleDealUnits.length"
      class="rounded-token border p-2"
      :class="makeDeal ? 'border-primary bg-primary/5' : 'border-border'"
    >
      <legend class="px-1 text-xs uppercase opacity-60">Deal</legend>
      <label class="inline-flex items-center gap-2 text-sm">
        <input v-model="makeDeal" type="checkbox" />
        <span>💼 Client decided — open a deal with the selected properties (they will be reserved)</span>
      </label>

      <div v-if="makeDeal" class="mt-2 space-y-1.5">
        <div
          v-for="u in dealUnits"
          :key="u.unit_id"
          class="rounded-token border border-border bg-bg px-2 py-1.5 text-xs"
        >
          <label class="inline-flex w-full items-center gap-2">
            <input v-model="u.include" type="checkbox" />
            <span class="flex-1">🏠 {{ u.label }}</span>
          </label>
          <!-- The box decision: include 1..max available (not taken) boxes. -->
          <div
            v-if="u.include && (availableBoxes[u.location_id] ?? 0) > 0"
            class="mt-1 flex items-center gap-2 border-t border-border pt-1"
          >
            <label class="inline-flex items-center gap-1.5">
              <input
                type="checkbox"
                :checked="u.box_count > 0"
                @change="u.box_count = $event.target.checked ? 1 : 0"
              />
              <span>Include box(es)</span>
            </label>
            <template v-if="u.box_count > 0">
              <select
                v-model.number="u.box_count"
                class="rounded-token border border-border bg-bg px-1.5 py-0.5 text-xs text-ink"
              >
                <option v-for="n in availableBoxes[u.location_id]" :key="n" :value="n">{{ n }}</option>
              </select>
              <span class="opacity-60">of {{ availableBoxes[u.location_id] }} available</span>
            </template>
          </div>
        </div>
      </div>
    </fieldset>

    <NextActionFields v-model="nextAction" :field-agents="fieldAgents" />

    <div class="flex gap-2">
      <BaseButton type="submit" :disabled="saving || !nextActionReady">Complete visit</BaseButton>
      <BaseButton type="button" variant="ghost" @click="emit('cancel')">Cancel</BaseButton>
    </div>
  </form>
</template>
