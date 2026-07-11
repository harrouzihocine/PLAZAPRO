<script setup>
import { computed, onMounted, ref } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'
import StatusTag from '@/components/ui/StatusTag.vue'
import { useDynamicList, itemLabel } from '@/composables/useDynamicList'
import { shortlistApi } from '@/features/clients/api'
import FinishToggle from '@/features/inventory/components/FinishToggle.vue'
import ProjectUnitsPicker from '@/features/inventory/components/ProjectUnitsPicker.vue'
import UnitBoxPicker from '@/features/inventory/components/UnitBoxPicker.vue'
import DesireFields from '@/features/clients/components/DesireFields.vue'
import { desireForm as makeDesireForm, desirePayload } from '@/features/clients/desire'
import { formatMoney } from '@/features/payments/money'
import DraftBanner from '@/features/drafts/DraftBanner.vue'
import NextActionFields from '@/features/pipeline/components/NextActionFields.vue'
import { useModalDraft } from '@/composables/useModalDraft'
import { confirmAction } from '@/composables/useConfirm'
import { unitLine } from '@/utils/format'
import { t } from '@/i18n'

// The fast-entry call log (qualification happens on the phone): direction
// defaults to outbound, what-was-discussed is one tap-chip list, free-text notes.
// The call MUST conclude — self-closing rule — into one of:
//  - Next action (plan a call / office / in-site visit) — with optional inline
//    qualification (Branch B properties shortlist, or Branch A desire capture);
//  - Desire  → the client goes to the desire list (its profile captured);
//  - Archive → the project is archived with a reason + a note;
//  - Deal    → THE deal opens on the apartments the client picked from the
//    interested list (new ones can be added on the fly — they are shortlisted
//    with the deal); each apartment carries its box decision. The call itself
//    is the provenance, so any caller can conclude into a deal. Creating the
//    deal closes the log workflow until it resolves (lost reopens it).
// Emits the ready payload; the parent submits.
const props = defineProps({
  client: { type: Object, default: null },
  projectId: { type: [String, Number], default: null },
  fieldAgents: { type: Array, default: () => [] },
  saving: { type: Boolean, default: false },
  desire: { type: Object, default: null },
  draftKey: { type: String, default: null },
  // Pre-tick the "select matching properties" branch with candidates the caller
  // already knows fit (e.g. the Desire Matches board) — same shape ProjectUnitsPicker
  // emits: { shortlistable_type, shortlistable_id, label, location_id }. A restored
  // draft still wins (useModalDraft's setForm runs after this initial value).
  initialProperties: { type: Array, default: () => [] },
})
const emit = defineEmits(['submit', 'cancel'])

const { items: callTopics } = useDynamicList('call_topics')
const { items: objectionReasons } = useDynamicList('objection_reasons')
const { items: archiveReasons } = useDynamicList('cancellation_reasons')

const direction = ref('outbound')
const notes = ref('')
const topics = ref([])
const objections = ref([])

// How the call concludes (self-closing rule): 'next_action' | 'desire' | 'archive' | 'deal'.
const conclusion = ref('next_action')
const nextAction = ref({ type: 'call', due_date: '', due_time: '', assigned_to: '' })
const archive = ref({ reason_id: '', note: '' })

// Deal conclusion state: the project's interested list (each row toggles into
// the deal and carries its box picks), plus on-the-fly additions.
const dealChoices = ref([]) // [{ unit_id, label, location_id, include, box_ids }]
const dealAdditions = ref([]) // ProjectUnitsPicker v-model (units-only + boxes)

// Inline qualification for the next-action path: null | 'properties' | 'desire'.
const branch = ref(props.initialProperties.length ? 'properties' : null)
const properties = ref([...props.initialProperties])
const desireForm = ref(makeDesireForm(props.desire))

// The project's current active shortlist — shown read-only so the agent can see
// what's already there instead of re-picking it blind (also hidden from the picker).
const existingShortlist = ref([]) // [{ shortlistable_type, shortlistable_id, label, state }]
const existingShortlistKeys = computed(() =>
  existingShortlist.value.map((i) => `${i.shortlistable_type}:${i.shortlistable_id}`),
)

// `p.price` is the PROPOSED finish's price — name the finish whenever the
// unit quotes a fini offer, so the quoted number is never ambiguous.
const priceText = (p, finish) => {
  if (!p?.price) return null
  const tag =
    p.price_fini != null && p.price_semi_fini != null
      ? finish === 'fini'
        ? t('inventory.finishFiniShort')
        : t('inventory.finishSemiShort')
      : p.price_fini != null
        ? t('inventory.finishFiniShort')
        : null
  return [tag, formatMoney(p.price)].filter(Boolean).join(' ')
}
const propertyLabel = (p, finish) =>
  p ? unitLine(p, { price: priceText(p, finish) }) : null

// The interested list = the shortlisted apartments/locals the client has not
// passed on. Loaded once; the deal picks from it.
onMounted(async () => {
  if (!props.projectId) return
  const items = await shortlistApi.list(props.projectId)
  existingShortlist.value = items.map((i) => ({
    shortlistable_type: i.shortlistable_type,
    shortlistable_id: i.shortlistable_id,
    label: propertyLabel(i.property, i.finish_type) ?? `${i.shortlistable_type} #${i.shortlistable_id}`,
    state: i.state,
  }))
  const restorable = new Map(dealChoices.value.map((c) => [c.unit_id, c]))
  dealChoices.value = items
    .filter(
      (i) =>
        i.shortlistable_type === 'unit' &&
        !['won', 'lost', 'visited_not_interested'].includes(i.state),
    )
    .map((i) => ({
      unit_id: i.shortlistable_id,
      label: propertyLabel(i.property, i.finish_type) ?? `unit #${i.shortlistable_id}`,
      location_id: i.property?.location_id ?? null,
      include: restorable.get(i.shortlistable_id)?.include ?? false,
      box_ids: restorable.get(i.shortlistable_id)?.box_ids ?? [],
      // The finish the shortlist proposed — switchable per included apartment.
      finish_type: restorable.get(i.shortlistable_id)?.finish_type ?? i.finish_type ?? null,
      price_semi_fini: i.property?.price_semi_fini ?? null,
      price_fini: i.property?.price_fini ?? null,
    }))
})

const draft = props.draftKey
  ? useModalDraft({
      key: props.draftKey,
      label: t('offline.label.callLog', { name: props.client?.full_name ?? '' }),
      getForm: () => ({
        direction: direction.value,
        notes: notes.value,
        topics: topics.value,
        objections: objections.value,
        conclusion: conclusion.value,
        nextAction: nextAction.value,
        archive: archive.value,
        dealChoices: dealChoices.value,
        dealAdditions: dealAdditions.value,
        branch: branch.value,
        properties: properties.value,
        desireForm: desireForm.value,
      }),
      setForm: (d) => {
        direction.value = d.direction ?? 'outbound'
        notes.value = d.notes ?? ''
        topics.value = d.topics ?? []
        objections.value = d.objections ?? []
        conclusion.value = d.conclusion ?? 'next_action'
        nextAction.value = d.nextAction ?? { type: 'call', due_date: '', due_time: '', assigned_to: '' }
        archive.value = d.archive ?? { reason_id: '', note: '' }
        dealChoices.value = d.dealChoices ?? []
        dealAdditions.value = d.dealAdditions ?? []
        branch.value = d.branch ?? null
        properties.value = d.properties ?? []
        desireForm.value = d.desireForm ?? makeDesireForm(props.desire)
      },
    })
  : null

function cancel() {
  emit('cancel')
}

// Wipe everything the agent selected or typed, back to a blank call log. The
// loaded interested list (dealChoices) stays visible — only its picks clear.
async function reset() {
  if (!(await confirmAction({ text: t('common.resetFormConfirm') }))) return
  direction.value = 'outbound'
  notes.value = ''
  topics.value = []
  objections.value = []
  conclusion.value = 'next_action'
  nextAction.value = { type: 'call', due_date: '', due_time: '', assigned_to: '' }
  archive.value = { reason_id: '', note: '' }
  dealAdditions.value = []
  branch.value = null
  properties.value = []
  desireForm.value = makeDesireForm(props.desire)
  dealChoices.value.forEach((c) => {
    c.include = false
    c.box_ids = []
  })
}

function toggleTopic(id) {
  const i = topics.value.indexOf(id)
  if (i === -1) topics.value.push(id)
  else topics.value.splice(i, 1)
}

function toggleObjection(id) {
  const i = objections.value.indexOf(id)
  if (i === -1) objections.value.push(id)
  else objections.value.splice(i, 1)
}

const CONCLUSIONS = computed(() => [
  { value: 'next_action', label: t('calls.planNextAction'), icon: 'pi pi-calendar-plus' },
  { value: 'desire', label: t('calls.toDesireList'), icon: 'pi pi-heart' },
  { value: 'archive', label: t('project.archive'), icon: 'pi pi-inbox' },
  { value: 'deal', label: t('calls.createDeal'), icon: 'pi pi-briefcase' },
])

// The Branch A desire capture (alongside any conclusion) still requires its notes.
const branchDesireReady = computed(
  () => branch.value !== 'desire' || !!(desireForm.value.notes ?? '').trim(),
)

// The apartments entering the deal: the ticked interested ones + the additions.
const includedDealUnits = computed(() => [
  ...dealChoices.value
    .filter((c) => c.include)
    .map((c) => ({ unit_id: c.unit_id, box_ids: c.box_ids ?? [], finish_type: c.finish_type ?? null })),
  ...dealAdditions.value
    .filter((p) => p.shortlistable_type === 'unit')
    .map((p) => ({
      unit_id: p.shortlistable_id,
      box_ids: p.box_ids ?? [],
      finish_type: p.finish_type ?? null,
    })),
])

// Every conclusion has its own completeness gate — nothing dangling. The
// interest qualification sits above them all, so its desire gate applies globally.
const ready = computed(() => {
  if (!branchDesireReady.value) return false
  switch (conclusion.value) {
    case 'next_action':
      return !!nextAction.value.due_date
    case 'desire':
      return !!(desireForm.value.notes ?? '').trim()
    case 'archive':
      return !!archive.value.reason_id && !!archive.value.note.trim()
    case 'deal':
      return includedDealUnits.value.length > 0
    default:
      return false
  }
})

function submit() {
  if (!ready.value) return
  const payload = {
    direction: direction.value,
    notes: notes.value.trim() || null,
    topics: topics.value,
    objections: objections.value,
  }

  // Interest qualification rides on the call regardless of how it concludes.
  if (branch.value === 'properties' && properties.value.length) {
    payload.properties = properties.value.map(
      ({ shortlistable_type, shortlistable_id, finish_type }) => ({
        shortlistable_type,
        shortlistable_id,
        finish_type: finish_type ?? null,
      }),
    )
  }
  // Branch-A desire is upserted top-level — except when the call concludes to the
  // desire list, where the same profile rides inside the closure (no double-send).
  if (branch.value === 'desire' && conclusion.value !== 'desire') {
    payload.desire = desirePayload(desireForm.value)
  }

  if (conclusion.value === 'next_action') {
    payload.next_action = { ...nextAction.value }
  } else if (conclusion.value === 'desire') {
    payload.closure = { type: 'desire', desire: desirePayload(desireForm.value) }
  } else if (conclusion.value === 'archive') {
    payload.closure = {
      type: 'archive',
      reason_id: archive.value.reason_id,
      note: archive.value.note.trim(),
    }
  } else if (conclusion.value === 'deal') {
    payload.closure = { type: 'deal', units: includedDealUnits.value }
  }

  draft?.complete()
  emit('submit', payload)
}
</script>

<template>
  <form class="space-y-4" @submit.prevent="submit">
    <DraftBanner :visible="!!draft?.restored.value" />
    <BaseSelect
      v-model="direction"
:label="$t('calls.direction')"
      required
      class="sm:max-w-xs"
      :clearable="false"
      :options="[
        { value: 'outbound', label: $t('calls.outbound') },
        { value: 'inbound', label: $t('calls.inbound') },
      ]"
    />

    <!-- Fast talking-points — everything discussed / how the call went, in taps. -->
    <fieldset v-if="callTopics.length" class="rounded-xl border border-line p-3">
      <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-mute">{{ $t('calls.discussed') }}</legend>
      <div class="flex flex-wrap gap-1.5">
        <button
          v-for="topic in callTopics"
          :key="topic.id"
          type="button"
          class="rounded-full border px-3 py-1.5 text-xs transition-colors"
          :class="
            topics.includes(topic.id)
              ? 'border-primary bg-highlight font-medium text-ink'
              : 'border-line text-mute hover:border-primary hover:text-ink'
          "
          @click="toggleTopic(topic.id)"
        >
          {{ itemLabel(topic) }}
        </button>
      </div>
    </fieldset>

    <!-- Concerns / objections the client raised — the "why not" signals the
         Voice-of-Client analytics mine to tell the promoteur what blocks sales. -->
    <fieldset v-if="objectionReasons.length" class="rounded-xl border border-line p-3">
      <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-mute">
        {{ $t('calls.objections') }}
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
          {{ itemLabel(o) }}
        </button>
      </div>
    </fieldset>

    <BaseTextarea v-model="notes" :label="$t('common.notes')" :rows="3" />

    <!-- Qualify the interest first — matched to inventory (shortlist) or the
         requirements captured (desire). Rides on the call however it concludes. -->
    <fieldset class="rounded-xl border border-line p-3">
      <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-mute">
        {{ $t('calls.qualifyClient') }}
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
          <i class="pi pi-building text-[10px]" aria-hidden="true" /> {{ $t('calls.selectMatching') }}
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
          <i class="pi pi-heart text-[10px]" aria-hidden="true" /> {{ $t('calls.noMatchCapture') }}
        </button>
      </div>
      <div v-if="branch === 'properties'">
        <div v-if="existingShortlist.length" class="mb-3">
          <p class="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-mute">
            {{ $t('calls.alreadyShortlisted') }}
          </p>
          <div class="flex flex-wrap gap-1.5">
            <span
              v-for="it in existingShortlist"
              :key="it.shortlistable_type + it.shortlistable_id"
              class="inline-flex items-center gap-1.5 rounded-full bg-highlight px-3 py-1 text-xs text-ink"
            >
              {{ it.label }}
              <StatusTag :value="it.state" />
            </span>
          </div>
        </div>
        <ProjectUnitsPicker v-model="properties" :exclude="existingShortlistKeys" />
      </div>
      <DesireFields v-if="branch === 'desire'" v-model="desireForm" />
    </fieldset>

    <!-- How the call concludes — the self-closing rule (exactly one outcome). -->
    <fieldset class="rounded-xl border border-line p-3">
      <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-mute">
        {{ $t('calls.howConclude') }}
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

      <!-- Next action — plan the follow-up (interest already qualified above). -->
      <template v-if="conclusion === 'next_action'">
        <NextActionFields v-model="nextAction" :field-agents="fieldAgents" />
      </template>

      <!-- Desire → the client goes to the desire list -->
      <DesireFields v-else-if="conclusion === 'desire'" v-model="desireForm" />

      <!-- Archive → reason (required) + note (required) -->
      <div v-else-if="conclusion === 'archive'" class="space-y-3">
        <BaseSelect
          v-model="archive.reason_id"
:label="$t('calls.reason')"
          required
          :placeholder="$t('calls.whyArchived')"
          :options="archiveReasons.map((r) => ({ value: r.id, label: itemLabel(r) }))"
        />
        <BaseTextarea v-model="archive.note" :label="$t('common.note')" required :rows="2" />
      </div>

      <!-- Deal → the client commits: pick from the interested list (each
           apartment with its box decision); new apartments can be added on the
           fly (they join the shortlist with the deal). Marks them Interested immediately and
           closes the log workflow until the deal resolves. -->
      <div v-else-if="conclusion === 'deal'" class="space-y-3">
        <div v-if="dealChoices.length" class="space-y-2">
          <p class="text-xs font-semibold uppercase tracking-wide text-mute">
            {{ $t('calls.interestedApartments') }}
          </p>
          <div
            v-for="c in dealChoices"
            :key="c.unit_id"
            class="rounded-lg border border-line bg-card px-3 py-2 text-xs"
          >
            <label class="inline-flex w-full cursor-pointer items-center gap-2 text-ink">
              <input v-model="c.include" type="checkbox" class="h-4 w-4 accent-primary" />
              <span class="flex-1">
                <i class="pi pi-home text-[10px] text-mute" aria-hidden="true" /> {{ c.label }}
              </span>
            </label>
            <div v-if="c.include" class="mt-2 border-t border-line pt-2">
              <!-- Both finishes quoted → pick which offer the client takes. -->
              <div v-if="c.price_semi_fini != null && c.price_fini != null" class="mb-2">
                <FinishToggle
                  :model-value="c.finish_type ?? 'semi_fini'"
                  :semi-fini="c.price_semi_fini"
                  :fini="c.price_fini"
                  @update:model-value="c.finish_type = $event"
                />
              </div>
              <p class="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-mute">
                {{ $t('calls.boxesWithApartment') }}
              </p>
              <UnitBoxPicker
                v-model="c.box_ids"
                :unit-id="c.unit_id"
                :location-id="c.location_id"
              />
            </div>
          </div>
        </div>
        <p v-else class="text-xs text-mute">
          {{ $t('calls.nothingInterestedYet') }}
        </p>
        <ProjectUnitsPicker v-model="dealAdditions" units-only with-boxes />
      </div>
    </fieldset>

    <div class="flex gap-2 pt-1">
      <BaseButton type="submit" :disabled="saving || !ready">{{ $t('calls.saveCall') }}</BaseButton>
      <BaseButton type="button" variant="ghost" @click="cancel">{{ $t('common.cancel') }}</BaseButton>
      <BaseButton type="button" variant="ghost" class="ms-auto" :disabled="saving" @click="reset">
        <i class="pi pi-refresh text-[11px]" aria-hidden="true" /> {{ $t('common.reset') }}
      </BaseButton>
    </div>
  </form>
</template>
