<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import Button from 'primevue/button'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import SaleStatusBadge from '@/features/inventory/components/SaleStatusBadge.vue'
import { stackingApi } from '@/features/inventory/api'
import { analyticsApi } from '@/features/analytics/api'
import { useAuthStore } from '@/features/settings/store'
import { formatMoney } from '@/features/payments/money'

const props = defineProps({
  locationId: { type: [String, Number], required: true },
})
const emit = defineEmits(['select'])

const blocks = ref([])
const loading = ref(false)
const selected = ref(null)

// Optional "interest heat" overlay (visits per unit) — manager/admin only. It
// reuses the unit-intelligence report and is loaded lazily on first toggle.
const auth = useAuthStore()
const canReports = computed(() => auth.can('reports.view'))
const showHeat = ref(false)
const heat = ref({})

async function loadHeat() {
  const rows = await analyticsApi.units({ location_id: props.locationId })
  heat.value = Object.fromEntries(rows.map((r) => [r.id, r.visits]))
}
function heatFor(id) {
  return heat.value[id] ?? 0
}
async function toggleHeat() {
  showHeat.value = !showHeat.value
  if (showHeat.value && !Object.keys(heat.value).length) await loadHeat()
}

// Cell colour = commercial state (same hues as the status badges everywhere).
const cellClass = {
  available:
    'border-green-300 bg-green-100 text-green-800 hover:border-green-400 dark:border-green-500/40 dark:bg-green-500/15 dark:text-green-300',
  interested:
    'border-amber-300 bg-amber-100 text-amber-800 hover:border-amber-400 dark:border-amber-500/40 dark:bg-amber-500/15 dark:text-amber-300',
  reserved:
    'border-slate-400 bg-slate-200 text-slate-800 hover:border-slate-500 dark:border-slate-400/40 dark:bg-slate-400/20 dark:text-slate-200',
  sold: 'border-sky-300 bg-sky-100 text-sky-800 hover:border-sky-400 dark:border-sky-500/40 dark:bg-sky-500/15 dark:text-sky-300',
}

const LEGEND = [
  { status: 'available', swatch: 'bg-green-400 dark:bg-green-500' },
  { status: 'interested', swatch: 'bg-amber-400 dark:bg-amber-500' },
  { status: 'reserved', swatch: 'bg-slate-400 dark:bg-slate-400' },
  { status: 'sold', swatch: 'bg-sky-400 dark:bg-sky-500' },
]

async function load() {
  loading.value = true
  try {
    blocks.value = await stackingApi.get(props.locationId)
  } finally {
    loading.value = false
  }
}

function pick(unit) {
  selected.value = unit
  emit('select', unit)
}

// Live countdown to a held unit's expiry (updates each second).
const now = ref(Date.now())
let ticker
const countdown = computed(() => {
  if (!selected.value?.expires_at) return null
  const ms = new Date(selected.value.expires_at).getTime() - now.value
  if (ms <= 0) return 'expired'
  const h = Math.floor(ms / 3.6e6)
  const m = Math.floor((ms % 3.6e6) / 6e4)
  const s = Math.floor((ms % 6e4) / 1000)
  return `${h}h ${String(m).padStart(2, '0')}m ${String(s).padStart(2, '0')}s`
})

// Let the parent refresh the plan (e.g. after a hold flips a status).
defineExpose({
  reload: async () => {
    await load()
    // Keep the selected cell in sync with the refreshed plan.
    if (selected.value) {
      const match = blocks.value
        .flatMap((b) => b.floors)
        .flatMap((f) => f.units)
        .find((u) => u.id === selected.value.id)
      selected.value = match ?? null
    }
  },
})

onMounted(() => {
  load()
  ticker = setInterval(() => (now.value = Date.now()), 1000)
})
onUnmounted(() => clearInterval(ticker))
watch(
  () => props.locationId,
  () => {
    heat.value = {}
    load()
    if (showHeat.value) loadHeat()
  },
)
</script>

<template>
  <SectionCard title="Stacking plan" icon="pi pi-table">
    <template #actions>
      <div class="flex flex-wrap items-center gap-3 text-xs text-mute">
        <span v-for="l in LEGEND" :key="l.status" class="flex items-center gap-1.5">
          <span class="inline-block h-2.5 w-2.5 rounded-sm" :class="l.swatch" aria-hidden="true" />
          {{ l.status }}
        </span>
        <Button
          v-if="canReports"
          label="Interest"
          icon="pi pi-bolt"
          size="small"
          :severity="showHeat ? null : 'secondary'"
          :outlined="!showHeat"
          @click="toggleHeat"
        />
      </div>
    </template>

    <p v-if="loading" class="py-4 text-center text-sm text-mute">Loading…</p>
    <EmptyState
      v-else-if="!blocks.length"
      icon="pi pi-table"
      title="Nothing placed on the plan yet"
      body="Set block / floor / position on units to draw the plan."
    />

    <div v-else class="space-y-5 overflow-x-auto pb-1">
      <div v-for="b in blocks" :key="b.block">
        <h3 class="mb-1.5 text-sm font-semibold text-ink">Block {{ b.block }}</h3>
        <div class="space-y-1">
          <div v-for="f in b.floors" :key="`${b.block}-${f.floor}`" class="flex items-center gap-2">
            <span class="num w-10 shrink-0 text-end text-xs text-mute">
              {{ f.floor ?? '—' }}
            </span>
            <div class="flex flex-wrap gap-1">
              <button
                v-for="u in f.units"
                :key="u.id"
                type="button"
                class="relative min-h-[44px] min-w-[44px] rounded-lg border px-2 text-xs font-semibold transition-all"
                :class="[
                  cellClass[u.sale_status] ?? 'border-line text-mute',
                  selected?.id === u.id ? 'ring-2 ring-primary ring-offset-1 ring-offset-card' : '',
                ]"
                :title="`${u.reference} · ${u.sale_status}`"
                @click="pick(u)"
              >
                {{ u.reference }}
                <span
                  v-if="showHeat && heatFor(u.id) > 0"
                  class="absolute -end-1.5 -top-1.5 rounded-full bg-primary px-1.5 text-[10px] font-bold leading-4 text-primary-contrast"
                  :title="`${heatFor(u.id)} visits`"
                >
                  {{ heatFor(u.id) }}
                </span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Selected cell detail; parent can inject actions (e.g. Reserve) via slot. -->
    <div
      v-if="selected"
      class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line bg-surface-50 p-3 dark:bg-surface-900"
    >
      <div>
        <p class="flex items-center gap-2 font-semibold text-ink">
          {{ selected.reference }}
          <SaleStatusBadge :status="selected.sale_status" :interested-count="selected.interested_count" />
        </p>
        <p class="num mt-0.5 text-xs text-mute">{{ formatMoney(selected.price) }}</p>
        <p v-if="countdown" class="num mt-0.5 text-xs font-semibold text-warning">
          <i class="pi pi-hourglass text-[10px]" aria-hidden="true" />
          {{ selected.sale_status === 'reserved' ? 'Reservation expires in' : 'Hold expires in' }}
          {{ countdown }}
        </p>
      </div>
      <slot name="actions" :unit="selected" />
    </div>
  </SectionCard>
</template>
