<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'
import TimeField from '@/components/base/TimeField.vue'
import StatusTag from '@/components/ui/StatusTag.vue'
import { useDynamicList } from '@/composables/useDynamicList'
import { toastError } from '@/composables/useConfirm'
import { shortlistApi } from '@/features/clients/api'
import ProjectUnitsPicker from '@/features/inventory/components/ProjectUnitsPicker.vue'
import UnitBoxPicker from '@/features/inventory/components/UnitBoxPicker.vue'
import DesireFields from '@/features/clients/components/DesireFields.vue'
import { desireForm as makeDesireForm, desirePayload } from '@/features/clients/desire'
import { formatMoney } from '@/features/payments/money'
import DraftBanner from '@/features/drafts/DraftBanner.vue'
import NextActionFields from '@/features/pipeline/components/NextActionFields.vue'
import { useModalDraft } from '@/composables/useModalDraft'
import { todayInput } from '@/utils/format'

// The rapid visit-completion log. It records the outcome + (office) the deal's
// property shortlist, then MUST conclude — self-closing rule — into one of:
//  - Next action → plan a call / office / in-site visit;
//  - Deal        → open THE deal on the interested properties (auto-marked Interested);
//  - Desire      → the client goes to the desire list (its profile captured);
//  - Archive     → the project is archived with a reason + a note.
// Emits the ready payload (next_action | closure + shortlist); parent submits.
const props = defineProps({
  visit: { type: Object, required: true },
  fieldAgents: { type: Array, default: () => [] },
  saving: { type: Boolean, default: false },
  canDeal: { type: Boolean, default: false },
  // A project can have many open in-site visits. Only the LAST remaining one
  // concludes the thread (next action / deal / desire / archive); the earlier
  // ones just record their result — though each may still open its OWN deal
  // for its apartment (one deal per committed apartment).
  isLastInSite: { type: Boolean, default: true },
  // The project's conclusion already exists (a deal is open or won on it) —
  // completing needs no conclusion; "nothing further" becomes the default.
  dealSettled: { type: Boolean, default: false },
  draftKey: { type: String, default: null },
})
const emit = defineEmits(['submit', 'cancel'])

const { items: officeOutcomes } = useDynamicList('visit_outcomes')
const { items: insiteOutcomes } = useDynamicList('insite_outcomes')
const { items: officeChecklist } = useDynamicList('office_visit_checklist')
const { items: objectionReasons } = useDynamicList('objection_reasons')
const { items: archiveReasons } = useDynamicList('cancellation_reasons')

const isOffice = computed(() => props.visit.type === 'office')
const hasDeal = computed(() => !!props.visit.client_project_id)

// Interim in-site visits (siblings still open) only record a result — the
// conclusion is decided on the last remaining one. Office visits always conclude.
const showConclusion = computed(() => isOffice.value || props.isLastInSite)

const outcomeId = ref('')
const notes = ref('')
const checklist = ref([])
const objections = ref([])

// When the visit ACTUALLY happened (in-site only, required) — the log's own
// completion moment is recorded server-side, so the gap between "visited" and
// "logged" stays visible to oversight. Prefilled to now: agents usually log
// right after the visit and just confirm.
const nowTime = () => {
  const d = new Date()
  const pad = (n) => String(n).padStart(2, '0')
  return `${pad(d.getHours())}:${pad(d.getMinutes())}`
}
const today = todayInput()
const visitedDate = ref(today)
const visitedTime = ref(nowTime())

// How the visit concludes: 'next_action' | 'deal' | 'desire' | 'archive' —
// plus 'none' when the deal already settled the thread.
const conclusion = ref(props.dealSettled ? 'none' : 'next_action')

// Interim in-site visit: the client may commit to THIS apartment right here —
// an optional deal closure opens its own deal (the sibling visits stay open).
const interimDeal = ref(false)
const nextAction = ref({ type: 'call', due_date: '', due_time: '', assigned_to: '' })
const archive = ref({ reason_id: '', note: '' })
const desireForm = ref(makeDesireForm(null))

const draft = props.draftKey
  ? useModalDraft({
      key: props.draftKey,
      label: `Complete ${props.visit.type === 'in_site' ? 'in-site' : 'office'} visit`,
      getForm: () => ({
        outcomeId: outcomeId.value,
        notes: notes.value,
        checklist: checklist.value,
        objections: objections.value,
        visitedDate: visitedDate.value,
        visitedTime: visitedTime.value,
        conclusion: conclusion.value,
        interimDeal: interimDeal.value,
        nextAction: nextAction.value,
        archive: archive.value,
        desireForm: desireForm.value,
      }),
      setForm: (d) => {
        outcomeId.value = d.outcomeId ?? ''
        notes.value = d.notes ?? ''
        checklist.value = d.checklist ?? []
        objections.value = d.objections ?? []
        visitedDate.value = d.visitedDate ?? today
        visitedTime.value = d.visitedTime ?? nowTime()
        conclusion.value = d.conclusion ?? (props.dealSettled ? 'none' : 'next_action')
        interimDeal.value = d.interimDeal ?? false
        nextAction.value = d.nextAction ?? { type: 'call', due_date: '', due_time: '', assigned_to: '' }
        archive.value = d.archive ?? { reason_id: '', note: '' }
        desireForm.value = d.desireForm ?? makeDesireForm(null)
      },
    })
  : null

function cancel() {
  emit('cancel')
}

// Office shortlist manager state: the deal's current active list, editable.
const shortlist = ref([])
const additions = ref([])
const dealUnits = ref([]) // [{ unit_id, label, location_id, include, box_ids }]

const propertyLabel = (p) =>
  p
    ? [
        p.reference,
        p.property_type,
        p.floor,
        p.area_sqm ? `${p.area_sqm} m²` : null,
        p.price ? formatMoney(p.price) : null,
      ]
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
        locked_reason: i.locked_reason ?? null,
      }))
  }
})

const selectedInsiteOutcome = computed(() =>
  insiteOutcomes.value.find((o) => o.id === outcomeId.value),
)
const insiteInterested = computed(() => selectedInsiteOutcome.value?.value === 'visited_interested')

// The units eligible for the deal: in-site → the visited unit; office → the
// units on the final shortlist (client-rejected ones are not offered).
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

const canOpenDeal = computed(() => eligibleDealUnits.value.length > 0)

// Keep the deal-unit working list in sync with what's eligible.
watch(eligibleDealUnits, (units) => {
  dealUnits.value = units.map((u) => {
    const prev = dealUnits.value.find((d) => d.unit_id === u.unit_id)
    return { ...u, include: prev?.include ?? !isOffice.value, box_ids: prev?.box_ids ?? [] }
  })
})

// A deal that becomes ineligible (outcome flipped) falls back to the default.
watch(canOpenDeal, (ok) => {
  if (!ok && conclusion.value === 'deal') conclusion.value = props.dealSettled ? 'none' : 'next_action'
  if (!ok) interimDeal.value = false
})

const includedDealUnits = computed(() => dealUnits.value.filter((u) => u.include))

function toggleChecklist(id) {
  const i = checklist.value.indexOf(id)
  if (i === -1) checklist.value.push(id)
  else checklist.value.splice(i, 1)
}

function toggleObjection(id) {
  const i = objections.value.indexOf(id)
  if (i === -1) objections.value.push(id)
  else objections.value.splice(i, 1)
}

const excludeKeys = computed(() =>
  shortlist.value.map((i) => `${i.shortlistable_type}:${i.shortlistable_id}`),
)
const finalShortlist = computed(() => [...shortlist.value, ...additions.value])

const CONCLUSIONS = computed(() => {
  // The deal already decided the thread: the visit is a plain record — though
  // the client may still commit to another apartment (its own deal).
  if (props.dealSettled) {
    return [
      { value: 'none', label: 'Nothing further (deal in progress)', icon: 'pi pi-check' },
      ...(canOpenDeal.value ? [{ value: 'deal', label: 'Open another deal', icon: 'pi pi-briefcase' }] : []),
    ]
  }
  return [
    { value: 'next_action', label: 'Plan next action', icon: 'pi pi-calendar-plus' },
    ...(canOpenDeal.value ? [{ value: 'deal', label: 'Open deal', icon: 'pi pi-briefcase' }] : []),
    { value: 'desire', label: 'To desire list', icon: 'pi pi-heart' },
    { value: 'archive', label: 'Archive', icon: 'pi pi-inbox' },
  ]
})

// In-site logs must state when the visit actually happened (date + time).
const visitedReady = computed(
  () => isOffice.value || (!!visitedDate.value && !!visitedTime.value),
)

const ready = computed(() => {
  if (!visitedReady.value) return false
  // Interim in-site log: result + notes only — unless a deal is being opened
  // for this apartment, which then needs its unit ticked.
  if (!showConclusion.value) return !interimDeal.value || includedDealUnits.value.length > 0
  switch (conclusion.value) {
    case 'none':
      return true
    case 'next_action':
      return !!nextAction.value.due_date
    case 'deal':
      return includedDealUnits.value.length > 0
    case 'desire':
      return !!(desireForm.value.notes ?? '').trim()
    case 'archive':
      return !!archive.value.reason_id && !!archive.value.note.trim()
    default:
      return false
  }
})

// A visit concluding onto the desire list / archive means the client bailed —
// the ≥1-shortlist rule is relaxed server-side, so it's fine to empty it here.
const bailing = computed(() => ['desire', 'archive'].includes(conclusion.value))

function submit() {
  if (!ready.value) return
  if (isOffice.value && hasDeal.value && !bailing.value && !finalShortlist.value.length) {
    toastError('At least one property must remain shortlisted (or archive / desire the project).')
    return
  }
  const payload = {
    outcome_id: outcomeId.value || null,
    notes: notes.value.trim() || null,
    checklist: checklist.value,
    objections: objections.value,
  }
  // In-site: when the visit actually happened, as the agent states it (local
  // wall clock — the app timezone end-to-end).
  if (!isOffice.value) {
    payload.visited_at = `${visitedDate.value} ${visitedTime.value}`
  }
  if (isOffice.value && hasDeal.value) {
    payload.shortlist = finalShortlist.value.map(({ shortlistable_type, shortlistable_id }) => ({
      shortlistable_type,
      shortlistable_id,
    }))
  }

  // Not the last in-site visit: record the result — plus, when the client
  // committed right here, this apartment's own deal.
  if (!showConclusion.value) {
    if (interimDeal.value && includedDealUnits.value.length) {
      payload.closure = {
        type: 'deal',
        units: includedDealUnits.value.map((u) => ({ unit_id: u.unit_id, box_ids: u.box_ids ?? [] })),
      }
    }
    draft?.complete()
    emit('submit', payload)
    return
  }

  if (conclusion.value === 'none') {
    // The deal already settled the thread — the completion is the whole log.
  } else if (conclusion.value === 'next_action') {
    payload.next_action = { ...nextAction.value }
  } else if (conclusion.value === 'deal') {
    payload.closure = {
      type: 'deal',
      units: includedDealUnits.value.map((u) => ({ unit_id: u.unit_id, box_ids: u.box_ids ?? [] })),
    }
  } else if (conclusion.value === 'desire') {
    payload.closure = { type: 'desire', desire: desirePayload(desireForm.value) }
  } else if (conclusion.value === 'archive') {
    payload.closure = {
      type: 'archive',
      reason_id: archive.value.reason_id,
      note: archive.value.note.trim(),
    }
  }

  draft?.complete()
  emit('submit', payload)
}
</script>

<template>
  <form class="space-y-4" @submit.prevent="submit">
    <DraftBanner :visible="!!draft?.restored.value" />
    <!-- In-site: one-tap result (didn't visit / interested / not interested / …). -->
    <fieldset v-if="!isOffice" class="rounded-xl border border-line p-3">
      <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-mute">Result</legend>
      <div class="flex flex-wrap gap-1.5">
        <button
          v-for="o in insiteOutcomes"
          :key="o.id"
          type="button"
          class="rounded-full border px-3 py-1.5 text-sm transition-colors"
          :class="
            outcomeId === o.id
              ? 'border-primary bg-highlight font-medium text-ink'
              : 'border-line text-mute hover:border-primary hover:text-ink'
          "
          @click="outcomeId = outcomeId === o.id ? '' : o.id"
        >
          {{ o.label }}
        </button>
      </div>
    </fieldset>

    <!-- When the visit actually happened — required on in-site logs. The log's
         own fill time is recorded automatically; this captures the real visit
         moment (agents often log after leaving the site). -->
    <fieldset v-if="!isOffice" class="rounded-xl border border-line p-3">
      <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-mute">
        When did the visit happen?<span class="text-danger" aria-hidden="true"> *</span>
      </legend>
      <div class="grid gap-3 sm:grid-cols-2">
        <label class="block">
          <span class="mb-1.5 block text-sm font-medium text-ink"
            >Visit date<span class="text-danger" aria-hidden="true"> *</span></span
          >
          <input
            v-model="visitedDate"
            type="date"
            :max="today"
            class="w-full rounded-md border border-line bg-card px-3 py-2 min-h-[42px] text-sm text-ink outline-none transition-colors focus:border-primary"
          />
        </label>
        <div class="block">
          <span class="mb-1.5 block text-sm font-medium text-ink"
            >Visit time<span class="text-danger" aria-hidden="true"> *</span></span
          >
          <TimeField v-model="visitedTime" aria-label="Visit time" />
        </div>
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
      <fieldset v-if="officeChecklist.length" class="rounded-xl border border-line p-3">
        <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-mute">
          What happened
        </legend>
        <div class="flex flex-wrap gap-1.5">
          <button
            v-for="c in officeChecklist"
            :key="c.id"
            type="button"
            class="rounded-full border px-3 py-1.5 text-xs transition-colors"
            :class="
              checklist.includes(c.id)
                ? 'border-primary bg-highlight font-medium text-ink'
                : 'border-line text-mute hover:border-primary hover:text-ink'
            "
            @click="toggleChecklist(c.id)"
          >
            {{ c.label }}
          </button>
        </div>
      </fieldset>

      <!-- The deal's property shortlist: keep / drop the listed ones and add
           new units or properties right here — the office-visit twin of the
           call log's "Qualify the client" picker. ≥1 unless bailing. -->
      <fieldset v-if="hasDeal" class="rounded-xl border border-line p-3">
        <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-mute">
          Property shortlist<template v-if="!bailing"> (at least one)</template>
        </legend>
        <div v-if="shortlist.length" class="mb-3 flex flex-wrap gap-1.5">
          <span
            v-for="(it, i) in shortlist"
            :key="it.shortlistable_type + it.shortlistable_id"
            class="inline-flex items-center gap-1.5 rounded-full bg-highlight px-3 py-1 text-xs text-ink"
          >
            {{ it.label }}
            <StatusTag v-if="it.locked_reason" :value="it.locked_reason" />
            <button
              v-else
              type="button"
              class="text-mute hover:text-danger"
              aria-label="Remove from shortlist"
              @click="shortlist.splice(i, 1)"
            >
              <i class="pi pi-times text-[10px]" aria-hidden="true" />
            </button>
          </span>
        </div>
        <ProjectUnitsPicker v-model="additions" :exclude="excludeKeys" />
      </fieldset>
    </template>

    <!-- Concerns / objections the client raised — mined by the Voice-of-Client
         analytics (an in-site visit's objections attribute to its unit). -->
    <fieldset v-if="objectionReasons.length" class="rounded-xl border border-line p-3">
      <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-mute">
        Objections / concerns
      </legend>
      <div class="flex flex-wrap gap-1.5">
        <button
          v-for="o in objectionReasons"
          :key="o.id"
          type="button"
          class="rounded-full border px-3 py-1.5 text-xs transition-colors"
          :class="
            objections.includes(o.id)
              ? 'border-warning bg-warning/10 font-medium text-ink'
              : 'border-line text-mute hover:border-warning hover:text-ink'
          "
          @click="toggleObjection(o.id)"
        >
          {{ o.label }}
        </button>
      </div>
    </fieldset>

    <BaseTextarea v-model="notes" label="Notes" :rows="3" />

    <!-- Interim in-site log (siblings still open): the conclusion waits for the
         last remaining visit — record just the result here. -->
    <p
      v-if="!showConclusion"
      class="flex items-center gap-2 rounded-lg border border-line bg-surface-50 px-3 py-2.5 text-sm text-mute dark:bg-surface-800/50"
    >
      <i class="pi pi-info-circle" aria-hidden="true" />
      More in-site visits are still open for this project — record this result; conclude on the
      last one.
    </p>

    <!-- …but the client may commit to THIS apartment right away: its own deal
         opens here (one deal per apartment; the sibling visits stay open). -->
    <fieldset v-if="!showConclusion && canOpenDeal" class="rounded-xl border border-line p-3">
      <label class="flex cursor-pointer items-center gap-2 text-sm text-ink">
        <input v-model="interimDeal" type="checkbox" class="h-4 w-4 accent-primary" />
        <span>
          <i class="pi pi-briefcase text-[11px] text-mute" aria-hidden="true" />
          The client takes this apartment — open its deal now
        </span>
      </label>
      <div v-if="interimDeal" class="mt-2 space-y-2 border-t border-line pt-2">
        <div v-for="u in dealUnits" :key="u.unit_id" class="text-xs">
          <p class="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-mute">
            Boxes with this apartment
          </p>
          <UnitBoxPicker v-model="u.box_ids" :unit-id="u.unit_id" :location-id="u.location_id" />
        </div>
      </div>
    </fieldset>

    <!-- How the visit concludes — the self-closing rule (exactly one outcome). -->
    <fieldset v-if="showConclusion" class="rounded-xl border border-line p-3">
      <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-mute">
        How does this visit conclude?
      </legend>
      <div class="mb-3 flex flex-wrap gap-1.5">
        <button
          v-for="c in CONCLUSIONS"
          :key="c.value"
          type="button"
          class="rounded-full border px-3 py-1.5 text-xs transition-colors"
          :class="
            conclusion === c.value
              ? 'border-primary bg-highlight font-medium text-ink'
              : 'border-line text-mute hover:border-primary hover:text-ink'
          "
          @click="conclusion = c.value"
        >
          <i :class="c.icon" class="text-[10px]" aria-hidden="true" /> {{ c.label }}
        </button>
      </div>

      <NextActionFields
        v-if="conclusion === 'next_action'"
        v-model="nextAction"
        :field-agents="fieldAgents"
        :current-unit="isOffice ? null : visit.unit"
      />

      <!-- Deal → commit the interested properties; each apartment carries its
           box decision (its linked boxes + the unlinked pool to link). -->
      <div v-else-if="conclusion === 'deal'" class="space-y-2">
        <div
          v-for="u in dealUnits"
          :key="u.unit_id"
          class="rounded-lg border border-line bg-card px-3 py-2 text-xs"
        >
          <label class="inline-flex w-full cursor-pointer items-center gap-2 text-ink">
            <input v-model="u.include" type="checkbox" class="h-4 w-4 accent-primary" />
            <span class="flex-1">
              <i class="pi pi-home text-[10px] text-mute" aria-hidden="true" /> {{ u.label }}
            </span>
          </label>
          <div v-if="u.include" class="mt-2 border-t border-line pt-2">
            <p class="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-mute">
              Boxes with this apartment
            </p>
            <UnitBoxPicker v-model="u.box_ids" :unit-id="u.unit_id" :location-id="u.location_id" />
          </div>
        </div>
      </div>

      <!-- Desire → the client goes to the desire list -->
      <DesireFields v-else-if="conclusion === 'desire'" v-model="desireForm" />

      <!-- Archive → reason (required) + note (required) -->
      <div v-else-if="conclusion === 'archive'" class="space-y-3">
        <BaseSelect
          v-model="archive.reason_id"
          label="Reason"
          required
          placeholder="Why is this archived?"
          :options="archiveReasons.map((r) => ({ value: r.id, label: r.label }))"
        />
        <BaseTextarea v-model="archive.note" label="Note" required :rows="2" />
      </div>
    </fieldset>

    <div class="flex gap-2 pt-1">
      <BaseButton type="submit" :disabled="saving || !ready">Complete visit</BaseButton>
      <BaseButton type="button" variant="ghost" @click="cancel">Cancel</BaseButton>
    </div>
  </form>
</template>
