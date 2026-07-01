<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import { useDynamicList } from '@/composables/useDynamicList'
import { documentsApi, scheduleApi, versementsApi } from '@/features/payments/api'
import { formatMoney } from '@/features/payments/money'
import { useAuthStore } from '@/features/settings/store'

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
const error = ref('')

const stateClass = {
  pending: 'bg-border text-ink',
  partial: 'bg-warning/15 text-warning',
  paid: 'bg-success/15 text-success',
  overdue: 'bg-danger/15 text-danger',
  cancelled: 'bg-surface text-ink opacity-70',
}

const inputClass =
  'w-full rounded-token border border-border bg-bg px-3 py-2 min-h-[44px] text-ink outline-none focus:border-primary'

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [sched, vers] = await Promise.all([
      scheduleApi.get(props.projectId),
      versementsApi.list(props.projectId),
    ])
    schedule.value = sched
    versements.value = vers.items
    meta.value = vers.meta
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Failed to load payments.'
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
  error.value = ''
  try {
    await scheduleApi.save(props.projectId, builder.rows)
    builder.open = false
    await load()
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Could not save the schedule.'
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
  error.value = ''
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
    error.value = e.response?.data?.message ?? 'Could not record the payment.'
  }
}

/* ---- Correct a versement (cancel-and-duplicate) -------------------------- */
const correctForm = reactive({ id: null, amount: '', paid_on: '', method_id: '', reference: '', reason: '' })
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
  error.value = ''
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
    error.value = e.response?.data?.message ?? 'Could not correct the payment.'
  }
}

/* ---- Receipt ------------------------------------------------------------- */
async function receipt(v) {
  error.value = ''
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
    else error.value = 'The receipt is still being generated — try again shortly.'
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Could not open the receipt.'
  }
}
</script>

<template>
  <div class="rounded-token border border-border bg-bg/40 p-3">
    <div class="mb-2 flex items-center justify-between">
      <h3 class="text-xs font-semibold uppercase opacity-60">Payments</h3>
      <div v-if="meta.balance !== null && meta.balance !== undefined" class="text-xs opacity-70">
        Balance <span class="font-semibold text-ink">{{ formatMoney(meta.balance) }}</span>
        <span class="opacity-60"> of {{ formatMoney(meta.total_price) }}</span>
      </div>
    </div>

    <p v-if="error" class="mb-2 rounded-token bg-danger/10 px-2 py-1 text-xs text-danger">{{ error }}</p>
    <p v-if="loading" class="py-2 text-xs opacity-60">Loading…</p>

    <template v-else>
      <!-- Schedule -->
      <div class="mb-3">
        <div class="mb-1 flex items-center justify-between">
          <span class="text-xs font-medium opacity-70">Instalment plan</span>
          <BaseButton v-if="canRecord" variant="ghost" class="!min-h-0 !px-2 !py-1 !text-xs" @click="openBuilder">
            {{ schedule.length ? 'Adjust' : 'Set schedule' }}
          </BaseButton>
        </div>

        <table v-if="schedule.length" class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs opacity-60">
              <th class="py-1">#</th>
              <th>Due</th>
              <th class="text-right">Amount</th>
              <th class="text-right">Paid</th>
              <th class="text-right">State</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in schedule" :key="s.id" class="border-t border-border/60">
              <td class="py-1">{{ s.installment_no }}</td>
              <td>{{ s.due_date }}</td>
              <td class="text-right">{{ formatMoney(s.amount) }}</td>
              <td class="text-right opacity-70">{{ formatMoney(s.paid_amount) }}</td>
              <td class="text-right">
                <span class="rounded-token px-2 py-0.5 text-xs font-medium" :class="stateClass[s.state]">{{ s.state }}</span>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-else class="py-1 text-xs opacity-60">No schedule set.</p>

        <!-- Schedule builder -->
        <div v-if="builder.open" class="mt-2 space-y-2 rounded-token border border-border p-2">
          <div v-for="(row, i) in builder.rows" :key="i" class="flex items-end gap-2">
            <BaseInput v-model="row.due_date" type="date" label="Due" class="flex-1" />
            <BaseInput v-model="row.amount" type="number" label="Amount" class="w-32" />
            <BaseButton variant="ghost" class="!min-h-0 !px-2 !py-1 !text-xs" @click="removeRow(i)">✕</BaseButton>
          </div>
          <div class="flex items-center justify-between text-xs">
            <BaseButton variant="ghost" class="!min-h-0 !px-2 !py-1 !text-xs" @click="addRow">+ Instalment</BaseButton>
            <span :class="plannedTotal === Number(totalPrice ?? 0).toFixed(2) ? 'text-success' : 'text-danger'">
              Planned {{ formatMoney(plannedTotal) }} / total {{ formatMoney(totalPrice) }}
            </span>
          </div>
          <div class="flex gap-2">
            <BaseButton class="!min-h-0 !px-3 !py-1 !text-xs" @click="saveSchedule">Save schedule</BaseButton>
            <BaseButton variant="ghost" class="!min-h-0 !px-3 !py-1 !text-xs" @click="builder.open = false">Cancel</BaseButton>
          </div>
        </div>
      </div>

      <!-- Versements -->
      <div>
        <div class="mb-1 flex items-center justify-between">
          <span class="text-xs font-medium opacity-70">Payments received</span>
          <BaseButton v-if="canRecord" variant="ghost" class="!min-h-0 !px-2 !py-1 !text-xs" @click="recordForm.open = !recordForm.open">
            Record payment
          </BaseButton>
        </div>

        <!-- Record form -->
        <div v-if="recordForm.open" class="mb-2 grid gap-2 rounded-token border border-border p-2 sm:grid-cols-2">
          <BaseInput v-model="recordForm.amount" type="number" label="Amount" />
          <BaseInput v-model="recordForm.paid_on" type="date" label="Paid on" />
          <label class="block">
            <span class="mb-1 block text-sm">Method</span>
            <select v-model="recordForm.method_id" :class="inputClass">
              <option value="">Select…</option>
              <option v-for="m in methods" :key="m.id" :value="m.id">{{ m.label }}</option>
            </select>
          </label>
          <label class="block">
            <span class="mb-1 block text-sm">Instalment (optional)</span>
            <select v-model="recordForm.schedule_item_id" :class="inputClass">
              <option value="">Unallocated</option>
              <option v-for="s in schedule" :key="s.id" :value="s.id">#{{ s.installment_no }} · {{ formatMoney(s.amount) }}</option>
            </select>
          </label>
          <BaseInput v-model="recordForm.reference" label="Reference" class="sm:col-span-2" />
          <div class="flex gap-2 sm:col-span-2">
            <BaseButton class="!min-h-0 !px-3 !py-1 !text-xs" @click="recordPayment">Record</BaseButton>
            <BaseButton variant="ghost" class="!min-h-0 !px-3 !py-1 !text-xs" @click="resetRecord">Cancel</BaseButton>
          </div>
        </div>

        <div v-if="versements.length" class="space-y-1">
          <div v-for="v in versements" :key="v.id" class="rounded-token border border-border/60 p-2 text-sm">
            <div class="flex flex-wrap items-center gap-2">
              <span class="font-medium">{{ formatMoney(v.amount) }}</span>
              <span class="opacity-70">{{ v.paid_on }}</span>
              <span v-if="v.method" class="opacity-70">· {{ v.method.label }}</span>
              <span v-if="v.reference" class="opacity-60">· {{ v.reference }}</span>
              <span v-if="v.supersedes_id" class="rounded-token bg-warning/15 px-2 py-0.5 text-xs text-warning">corrected</span>
              <div class="ml-auto flex gap-1">
                <BaseButton variant="ghost" class="!min-h-0 !px-2 !py-1 !text-xs" @click="receipt(v)">
                  {{ v.document_id ? 'Receipt' : 'Generate receipt' }}
                </BaseButton>
                <BaseButton v-if="canCorrect" variant="ghost" class="!min-h-0 !px-2 !py-1 !text-xs" @click="startCorrect(v)">Correct</BaseButton>
              </div>
            </div>

            <!-- Correction form -->
            <div v-if="correctForm.id === v.id" class="mt-2 grid gap-2 border-t border-border pt-2 sm:grid-cols-2">
              <BaseInput v-model="correctForm.amount" type="number" label="Corrected amount" />
              <BaseInput v-model="correctForm.paid_on" type="date" label="Paid on" />
              <label class="block">
                <span class="mb-1 block text-sm">Method</span>
                <select v-model="correctForm.method_id" :class="inputClass">
                  <option v-for="m in methods" :key="m.id" :value="m.id">{{ m.label }}</option>
                </select>
              </label>
              <BaseInput v-model="correctForm.reference" label="Reference" />
              <BaseInput v-model="correctForm.reason" label="Reason" class="sm:col-span-2" />
              <div class="flex gap-2 sm:col-span-2">
                <BaseButton class="!min-h-0 !px-3 !py-1 !text-xs" @click="submitCorrect">Save correction</BaseButton>
                <BaseButton variant="ghost" class="!min-h-0 !px-3 !py-1 !text-xs" @click="correctForm.id = null">Cancel</BaseButton>
              </div>
            </div>
          </div>
        </div>
        <p v-else class="py-1 text-xs opacity-60">No payments recorded.</p>
      </div>
    </template>
  </div>
</template>
