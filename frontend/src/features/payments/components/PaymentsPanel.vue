<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Button from 'primevue/button'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import StatusTag from '@/components/ui/StatusTag.vue'
import { useDynamicList } from '@/composables/useDynamicList'
import { documentsApi, scheduleApi, versementsApi } from '@/features/payments/api'
import { formatMoney } from '@/features/payments/money'
import { useAuthStore } from '@/features/settings/store'
import { toastError } from '@/composables/useConfirm'
import { formatDate } from '@/utils/format'

const props = defineProps({
  projectId: { type: [String, Number], required: true },
  totalPrice: { type: [String, Number, null], default: null },
})

const auth = useAuthStore()
const canRecord = computed(() => auth.can('versements.record'))
const canCorrect = computed(() => auth.can('versements.cancel'))
const canGenerate = computed(() => auth.can('documents.generate'))

const { items: methods } = useDynamicList('payment_methods')

const schedule = ref([])
const versements = ref([])
const meta = ref({})
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const [sched, vers] = await Promise.all([
      scheduleApi.get(props.projectId),
      versementsApi.list(props.projectId),
    ])
    schedule.value = sched
    versements.value = vers.items
    meta.value = vers.meta
  } catch (e) {
    toastError(e.response?.data?.message ?? 'Failed to load payments.')
  } finally {
    loading.value = false
  }
}

onMounted(load)

/* ---- Schedule builder ---------------------------------------------------- */
const builder = reactive({ open: false, rows: [{ due_date: '', amount: '' }] })
const plannedTotal = computed(() =>
  builder.rows.reduce((sum, r) => sum + (Number.parseFloat(r.amount) || 0), 0).toFixed(2),
)

function addRow() {
  builder.rows.push({ due_date: '', amount: '' })
}
function removeRow(i) {
  builder.rows.splice(i, 1)
}
function openBuilder() {
  builder.open = true
  builder.rows = schedule.value.length
    ? schedule.value.map((s) => ({ due_date: s.due_date, amount: s.amount }))
    : [{ due_date: '', amount: '' }]
}
async function saveSchedule() {
  try {
    await scheduleApi.save(props.projectId, builder.rows)
    builder.open = false
    await load()
  } catch (e) {
    toastError(e.response?.data?.message ?? 'Could not save the schedule.')
  }
}

/* ---- Record a versement -------------------------------------------------- */
const recordForm = reactive({
  open: false,
  amount: '',
  paid_on: new Date().toISOString().slice(0, 10),
  method_id: '',
  reference: '',
  schedule_item_id: '',
})
function resetRecord() {
  Object.assign(recordForm, {
    open: false,
    amount: '',
    paid_on: new Date().toISOString().slice(0, 10),
    method_id: '',
    reference: '',
    schedule_item_id: '',
  })
}
async function recordPayment() {
  try {
    const payload = {
      amount: recordForm.amount,
      paid_on: recordForm.paid_on,
      method_id: recordForm.method_id,
      reference: recordForm.reference || null,
      schedule_item_id: recordForm.schedule_item_id || null,
    }
    await versementsApi.record(props.projectId, payload)
    resetRecord()
    await load()
  } catch (e) {
    toastError(e.response?.data?.message ?? 'Could not record the payment.')
  }
}

/* ---- Correct a versement (cancel-and-duplicate) -------------------------- */
const correctForm = reactive({
  id: null,
  amount: '',
  paid_on: '',
  method_id: '',
  reference: '',
  reason: '',
})
function startCorrect(v) {
  Object.assign(correctForm, {
    id: v.id,
    amount: v.amount,
    paid_on: v.paid_on,
    method_id: v.method?.id ?? '',
    reference: v.reference ?? '',
    reason: '',
  })
}
async function submitCorrect() {
  try {
    await versementsApi.correct(correctForm.id, {
      amount: correctForm.amount,
      paid_on: correctForm.paid_on,
      method_id: correctForm.method_id,
      reference: correctForm.reference || null,
      reason: correctForm.reason,
    })
    correctForm.id = null
    await load()
  } catch (e) {
    toastError(e.response?.data?.message ?? 'Could not correct the payment.')
  }
}

/* ---- Receipt ------------------------------------------------------------- */
async function receipt(v) {
  try {
    let docId = v.document_id
    if (!docId && canGenerate.value) {
      const doc = await documentsApi.generateReceipt(v.id)
      docId = doc.id
      await load()
      if (doc.download_url) return window.open(doc.download_url, '_blank')
    }
    if (!docId) return
    const doc = await documentsApi.get(docId)
    if (doc.download_url) window.open(doc.download_url, '_blank')
    else toastError('The receipt is still being generated — try again shortly.')
  } catch (e) {
    toastError(e.response?.data?.message ?? 'Could not open the receipt.')
  }
}
</script>

<template>
  <SectionCard title="Payments" icon="pi pi-wallet">
    <template #actions>
      <span
        v-if="meta.balance !== null && meta.balance !== undefined"
        class="num text-xs text-mute"
      >
        Balance <span class="font-semibold text-ink">{{ formatMoney(meta.balance) }}</span> of
        {{ formatMoney(meta.total_price) }}
      </span>
    </template>

    <p v-if="loading" class="py-2 text-sm text-mute">Loading…</p>

    <template v-else>
      <!-- Schedule -->
      <div class="mb-4">
        <div class="mb-2 flex items-center justify-between">
          <span class="text-sm font-medium text-ink">Instalment plan</span>
          <Button
            v-if="canRecord"
            :label="schedule.length ? 'Adjust' : 'Set schedule'"
            icon="pi pi-calendar-plus"
            size="small"
            text
            @click="openBuilder"
          />
        </div>

        <div v-if="schedule.length" class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs text-mute">
                <th class="py-1.5 font-medium">#</th>
                <th class="font-medium">Due</th>
                <th class="text-right font-medium">Amount</th>
                <th class="text-right font-medium">Paid</th>
                <th class="text-right font-medium">State</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in schedule" :key="s.id" class="border-t border-line">
                <td class="num py-2">{{ s.installment_no }}</td>
                <td>{{ formatDate(s.due_date) }}</td>
                <td class="num text-right">{{ formatMoney(s.amount) }}</td>
                <td class="num text-right text-mute">{{ formatMoney(s.paid_amount) }}</td>
                <td class="py-1 text-right"><StatusTag :value="s.state" /></td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else class="py-1 text-sm text-mute">No schedule set.</p>

        <!-- Schedule builder -->
        <div v-if="builder.open" class="mt-3 space-y-3 rounded-xl border border-line p-3">
          <div v-for="(row, i) in builder.rows" :key="i" class="flex items-end gap-2">
            <BaseInput v-model="row.due_date" type="date" label="Due" class="flex-1" />
            <BaseInput v-model="row.amount" type="number" label="Amount" class="w-36" />
            <Button
              icon="pi pi-times"
              text
              rounded
              severity="danger"
              size="small"
              aria-label="Remove instalment"
              @click="removeRow(i)"
            />
          </div>
          <div class="flex items-center justify-between text-xs">
            <Button label="Instalment" icon="pi pi-plus" size="small" text @click="addRow" />
            <span
              class="num"
              :class="
                plannedTotal === Number(totalPrice ?? 0).toFixed(2) ? 'text-success' : 'text-danger'
              "
            >
              Planned {{ formatMoney(plannedTotal) }} / total {{ formatMoney(totalPrice) }}
            </span>
          </div>
          <div class="flex gap-2">
            <Button label="Save schedule" icon="pi pi-check" size="small" @click="saveSchedule" />
            <Button
              label="Cancel"
              size="small"
              severity="secondary"
              outlined
              @click="builder.open = false"
            />
          </div>
        </div>
      </div>

      <!-- Versements -->
      <div>
        <div class="mb-2 flex items-center justify-between">
          <span class="text-sm font-medium text-ink">Payments received</span>
          <Button
            v-if="canRecord"
            label="Record payment"
            icon="pi pi-plus"
            size="small"
            @click="recordForm.open = !recordForm.open"
          />
        </div>

        <!-- Record form -->
        <div
          v-if="recordForm.open"
          class="mb-3 grid gap-3 rounded-xl border border-line p-3 sm:grid-cols-2"
        >
          <BaseInput v-model="recordForm.amount" type="number" label="Amount" />
          <BaseInput v-model="recordForm.paid_on" type="date" label="Paid on" />
          <BaseSelect
            v-model="recordForm.method_id"
            label="Method"
            placeholder="Select…"
            :options="methods.map((m) => ({ value: m.id, label: m.label }))"
          />
          <BaseSelect
            v-model="recordForm.schedule_item_id"
            label="Instalment (optional)"
            placeholder="Unallocated"
            :options="
              schedule.map((s) => ({
                value: s.id,
                label: `#${s.installment_no} · ${formatMoney(s.amount)}`,
              }))
            "
          />
          <BaseInput v-model="recordForm.reference" label="Reference" class="sm:col-span-2" />
          <div class="flex gap-2 sm:col-span-2">
            <Button label="Record" icon="pi pi-check" size="small" @click="recordPayment" />
            <Button
              label="Cancel"
              size="small"
              severity="secondary"
              outlined
              @click="resetRecord"
            />
          </div>
        </div>

        <div v-if="versements.length" class="space-y-2">
          <div
            v-for="v in versements"
            :key="v.id"
            class="rounded-lg border border-line p-3 text-sm"
          >
            <div class="flex flex-wrap items-center gap-2">
              <span class="num font-semibold text-ink">{{ formatMoney(v.amount) }}</span>
              <span class="text-mute">{{ formatDate(v.paid_on) }}</span>
              <span v-if="v.method" class="text-mute">· {{ v.method.label }}</span>
              <span v-if="v.reference" class="text-mute">· {{ v.reference }}</span>
              <StatusTag v-if="v.supersedes_id" value="corrected" label="corrected" />
              <div class="ml-auto flex gap-1">
                <Button
                  :label="v.document_id ? 'Receipt' : 'Generate receipt'"
                  icon="pi pi-file-pdf"
                  size="small"
                  text
                  @click="receipt(v)"
                />
                <Button
                  v-if="canCorrect"
                  label="Correct"
                  icon="pi pi-history"
                  size="small"
                  text
                  severity="secondary"
                  @click="startCorrect(v)"
                />
              </div>
            </div>

            <!-- Correction form -->
            <div
              v-if="correctForm.id === v.id"
              class="mt-3 grid gap-3 border-t border-line pt-3 sm:grid-cols-2"
            >
              <BaseInput v-model="correctForm.amount" type="number" label="Corrected amount" />
              <BaseInput v-model="correctForm.paid_on" type="date" label="Paid on" />
              <BaseSelect
                v-model="correctForm.method_id"
                label="Method"
                :clearable="false"
                :options="methods.map((m) => ({ value: m.id, label: m.label }))"
              />
              <BaseInput v-model="correctForm.reference" label="Reference" />
              <BaseInput v-model="correctForm.reason" label="Reason" class="sm:col-span-2" />
              <div class="flex gap-2 sm:col-span-2">
                <Button
                  label="Save correction"
                  icon="pi pi-check"
                  size="small"
                  @click="submitCorrect"
                />
                <Button
                  label="Cancel"
                  size="small"
                  severity="secondary"
                  outlined
                  @click="correctForm.id = null"
                />
              </div>
            </div>
          </div>
        </div>
        <p v-else class="py-1 text-sm text-mute">No payments recorded.</p>
      </div>
    </template>
  </SectionCard>
</template>
