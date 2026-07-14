<script setup>
import { computed, reactive, ref } from 'vue'
import Button from 'primevue/button'
import BaseSelect from '@/components/base/BaseSelect.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'
import NextActionFields from '@/features/pipeline/components/NextActionFields.vue'
import { useDynamicList, itemLabel } from '@/composables/useDynamicList'
import { dateInputValue, timeInputValue, unitLine } from '@/utils/format'
import { t } from '@/i18n'

// Edit a past log (a call or a visit rapport). A correction is never an in-place
// edit: the server cancels the original and inserts a new version, both kept in
// the timeline with the reason. This form only edits the RAPPORT content; the
// heavy creation-time concerns (qualification, how a call concludes) are not
// reopened here.
//
// When this is the LAST log that still owns the open next action (`plan` given),
// the follow-up it created can be re-planned in the same step — the server keeps
// exactly one open plan, so the change is a correction of that plan.
const props = defineProps({
  // The timeline entry: { kind: 'call' | 'visit', data }.
  entry: { type: Object, required: true },
  fieldAgents: { type: Array, default: () => [] },
  saving: { type: Boolean, default: false },
  // The open next action this log created, when this is the last log — enables
  // the "also change the follow-up" section. Null otherwise.
  plan: { type: Object, default: null },
})
const emit = defineEmits(['submit', 'cancel'])

const isCall = computed(() => props.entry.kind === 'call')
const data = props.entry.data

// Shared input styling for the native datetime-local fields (mirrors NextActionFields).
const inputClass =
  'w-full rounded-md border border-line bg-card px-3 py-2 min-h-[42px] text-sm text-ink outline-none transition-colors focus:border-primary'

const { items: callTopics } = useDynamicList('call_topics')
const { items: objectionReasons } = useDynamicList('objection_reasons')
const { items: officeChecklist } = useDynamicList('office_visit_checklist')
const { items: changeReasons } = useDynamicList('next_action_change_reasons')

// Local ISO instant → 'YYYY-MM-DDTHH:mm' for a datetime-local input, in the
// app's Africa/Algiers wall clock (never toISOString). Sent back verbatim; the
// backend parses it in the app timezone.
function dateTimeLocal(value) {
  const date = dateInputValue(value)
  if (!date) return ''
  const time = timeInputValue(value)
  return `${date}T${time || '00:00'}`
}

// --- Rapport fields (prefilled from the current version) --------------------
const form = reactive({
  // Call
  direction: data.direction ?? 'outbound',
  called_at: dateTimeLocal(data.called_at),
  // Visit
  scheduled_at: dateTimeLocal(data.scheduled_at),
  visited_at: dateTimeLocal(data.visited_at),
  // Shared
  notes: data.notes ?? '',
  topics: [...(data.topics ?? [])],
  objections: [...(data.objections ?? [])],
  checklist: [...(data.checklist ?? [])],
})

const reason = ref('')

// --- Optional follow-up change ----------------------------------------------
const changePlan = ref(false)
const planForm = reactive({ type: 'call', due_date: '', due_time: '', assigned_to: '' })
const planReasonId = ref(null)
const planNote = ref('')

function armPlan() {
  changePlan.value = !changePlan.value
  if (changePlan.value && props.plan) {
    planForm.type = props.plan.type
    planForm.due_date = dateInputValue(props.plan.due_at)
    planForm.due_time = timeInputValue(props.plan.due_at)
    planForm.assigned_to = props.plan.assigned_to?.id ?? ''
  }
}

function toggle(list, id) {
  const i = list.indexOf(id)
  if (i === -1) list.push(id)
  else list.splice(i, 1)
}

const ready = computed(() => {
  if (!reason.value.trim()) return false
  if (isCall.value ? !form.direction : !form.scheduled_at) return false
  if (changePlan.value && (!planReasonId.value || !planForm.due_date)) return false
  return true
})

const dealWarning = computed(
  () => data.spawned_deal && !data.spawned_deal.locked && data.spawned_deal.state === 'open',
)

function submit() {
  if (!ready.value) return

  const correction = { reason: reason.value.trim(), notes: form.notes.trim() || null }
  if (isCall.value) {
    correction.direction = form.direction
    correction.topics = form.topics
    correction.objections = form.objections
    if (form.called_at) correction.called_at = form.called_at
  } else {
    // Type + unit are carried through unchanged (the request requires them);
    // the rapport content is what this form edits.
    correction.type = data.type
    if (data.type === 'in_site') correction.unit_id = data.unit?.id ?? null
    correction.scheduled_at = form.scheduled_at
    if (form.visited_at) correction.visited_at = form.visited_at
    correction.checklist = form.checklist
    correction.objections = form.objections
  }

  const planChange =
    changePlan.value && props.plan
      ? {
          reason_id: planReasonId.value,
          note: planNote.value.trim() || null,
          type: planForm.type,
          due_date: planForm.due_date,
          due_time: planForm.due_time || null,
          assigned_to: planForm.assigned_to || null,
        }
      : null

  emit('submit', { correction, planChange })
}
</script>

<template>
  <form class="space-y-4" @submit.prevent="submit">
    <!-- Editing a log that opened a still-waiting deal cancels that deal. -->
    <p
      v-if="dealWarning"
      class="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2.5 text-sm text-ink dark:border-amber-500/40 dark:bg-amber-500/10"
    >
      <i class="pi pi-exclamation-triangle mt-0.5 text-amber-600 dark:text-amber-400" aria-hidden="true" />
      <span>{{ $t('pipeline.editCancelsDeal') }}</span>
    </p>

    <!-- CALL fields -->
    <template v-if="isCall">
      <BaseSelect
        v-model="form.direction"
        :label="$t('calls.direction')"
        required
        class="sm:max-w-xs"
        :clearable="false"
        :options="[
          { value: 'outbound', label: $t('calls.outbound') },
          { value: 'inbound', label: $t('calls.inbound') },
        ]"
      />

      <label class="block sm:max-w-xs">
        <span class="mb-1.5 block text-sm font-medium text-ink">{{ $t('pipeline.calledAt') }}</span>
        <input v-model="form.called_at" type="datetime-local" :class="inputClass" />
      </label>

      <fieldset v-if="callTopics.length" class="rounded-xl border border-line p-3">
        <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-mute">{{ $t('calls.discussed') }}</legend>
        <div class="flex flex-wrap gap-1.5">
          <button
            v-for="topic in callTopics"
            :key="topic.id"
            type="button"
            class="rounded-full border px-3 py-1.5 text-xs transition-colors"
            :class="
              form.topics.includes(topic.id)
                ? 'border-primary bg-highlight font-medium text-ink'
                : 'border-line text-mute hover:border-primary hover:text-ink'
            "
            @click="toggle(form.topics, topic.id)"
          >
            {{ itemLabel(topic) }}
          </button>
        </div>
      </fieldset>
    </template>

    <!-- VISIT fields -->
    <template v-else>
      <div class="rounded-lg bg-surface-50 p-3 text-sm dark:bg-surface-800/50">
        <p class="text-[11px] font-medium uppercase tracking-wide text-mute">
          {{ entry.data.type === 'in_site' ? $t('status.in_site_visit') : $t('status.office_visit') }}
        </p>
        <p v-if="entry.data.unit" class="mt-1 font-medium text-ink">{{ unitLine(entry.data.unit) }}</p>
      </div>

      <div class="grid gap-3 sm:grid-cols-2">
        <label class="block">
          <span class="mb-1.5 block text-sm font-medium text-ink">{{ $t('status.scheduled') }}<span class="text-danger" aria-hidden="true"> *</span></span>
          <input v-model="form.scheduled_at" type="datetime-local" :class="inputClass" />
        </label>
        <label class="block">
          <span class="mb-1.5 block text-sm font-medium text-ink">{{ $t('pipeline.visited') }}</span>
          <input v-model="form.visited_at" type="datetime-local" :class="inputClass" />
        </label>
      </div>

      <fieldset v-if="officeChecklist.length" class="rounded-xl border border-line p-3">
        <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-mute">{{ $t('pipeline.checklist') }}</legend>
        <div class="flex flex-wrap gap-1.5">
          <button
            v-for="item in officeChecklist"
            :key="item.id"
            type="button"
            class="rounded-full border px-3 py-1.5 text-xs transition-colors"
            :class="
              form.checklist.includes(item.id)
                ? 'border-primary bg-highlight font-medium text-ink'
                : 'border-line text-mute hover:border-primary hover:text-ink'
            "
            @click="toggle(form.checklist, item.id)"
          >
            {{ itemLabel(item) }}
          </button>
        </div>
      </fieldset>
    </template>

    <!-- Objections (shared) -->
    <fieldset v-if="objectionReasons.length" class="rounded-xl border border-line p-3">
      <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-mute">{{ $t('pipeline.objections') }}</legend>
      <div class="flex flex-wrap gap-1.5">
        <button
          v-for="o in objectionReasons"
          :key="o.id"
          type="button"
          class="rounded-full border px-3 py-1.5 text-xs transition-colors"
          :class="
            form.objections.includes(o.id)
              ? 'border-warning bg-warning/10 font-medium text-ink'
              : 'border-line text-mute hover:border-warning hover:text-ink'
          "
          @click="toggle(form.objections, o.id)"
        >
          {{ itemLabel(o) }}
        </button>
      </div>
    </fieldset>

    <BaseTextarea v-model="form.notes" :label="$t('common.notes')" :rows="3" />

    <!-- The follow-up this log created — re-plan it in the same step. -->
    <div v-if="plan" class="rounded-xl border border-line p-3">
      <label class="flex cursor-pointer items-center gap-2 text-sm text-ink">
        <input type="checkbox" class="h-4 w-4 accent-primary" :checked="changePlan" @change="armPlan" />
        <span class="font-medium">{{ $t('pipeline.alsoChangeFollowUp') }}</span>
      </label>
      <div v-if="changePlan" class="mt-3 space-y-3">
        <NextActionFields
          :model-value="planForm"
          :field-agents="fieldAgents"
          @update:model-value="(v) => Object.assign(planForm, v)"
        />
        <BaseSelect
          v-model="planReasonId"
          :label="$t('pipeline.changeReason')"
          required
          :placeholder="$t('pipeline.pickReason')"
          :options="changeReasons.map((r) => ({ value: r.id, label: itemLabel(r) }))"
        />
        <BaseTextarea v-model="planNote" :label="$t('common.note')" :rows="2" />
      </div>
    </div>

    <!-- The correction reason — mandatory, kept on the cancelled original. -->
    <BaseTextarea v-model="reason" :label="$t('pipeline.editReason')" required :rows="2" :placeholder="$t('pipeline.editReasonHint')" />

    <div class="flex gap-2 pt-1">
      <Button type="submit" :label="$t('pipeline.saveChange')" size="small" :disabled="saving || !ready" />
      <Button type="button" :label="$t('common.cancel')" size="small" severity="secondary" outlined @click="emit('cancel')" />
    </div>
  </form>
</template>
