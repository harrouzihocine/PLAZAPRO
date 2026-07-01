<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import BaseCard from '@/components/base/BaseCard.vue'
import { stackingApi } from '@/features/inventory/api'
import { analyticsApi } from '@/features/analytics/api'
import { useAuthStore } from '@/features/settings/store'

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

const cellClass = {
  available: 'bg-success/20 border-success/40 text-success',
  reserved: 'bg-warning/20 border-warning/40 text-warning',
  sold: 'bg-danger/20 border-danger/40 text-danger',
}

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

// Live countdown to a reserved unit's expiry (updates each second).
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

// Let the parent refresh the plan (e.g. after a reservation flips a status).
defineExpose({
  reload: async () => {
    await load()
    // Keep the selected cell in sync with the refreshed plan.
    if (selected.value) {
      const match = blocks.value.flatMap((b) => b.floors).flatMap((f) => f.units).find((u) => u.id === selected.value.id)
      selected.value = match ?? null
    }
  },
})

onMounted(() => {
  load()
  ticker = setInterval(() => (now.value = Date.now()), 1000)
})
onUnmounted(() => clearInterval(ticker))
watch(() => props.locationId, () => {
  heat.value = {}
  load()
  if (showHeat.value) loadHeat()
})
</script>

<template>
  <BaseCard>
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 class="font-semibold">Stacking plan</h2>
      <div class="flex items-center gap-3 text-xs">
        <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded-sm bg-success/40"></span>available</span>
        <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded-sm bg-warning/40"></span>reserved</span>
        <span class="flex items-center gap-1"><span class="inline-block h-3 w-3 rounded-sm bg-danger/40"></span>sold</span>
        <button
          v-if="canReports"
          type="button"
          class="rounded-token border border-border px-2 py-1"
          :class="showHeat ? 'bg-primary text-on-primary' : ''"
          @click="toggleHeat"
        >
          🔥 Interest
        </button>
      </div>
    </div>

    <p v-if="loading" class="py-4 text-center text-sm opacity-60">Loading…</p>
    <p v-else-if="!blocks.length" class="py-4 text-center text-sm opacity-60">
      No units placed on the plan yet (set block / floor / position on units).
    </p>

    <div v-else class="mt-3 space-y-4 overflow-x-auto">
      <div v-for="b in blocks" :key="b.block">
        <h3 class="mb-1 text-sm font-medium opacity-80">Block {{ b.block }}</h3>
        <div class="space-y-1">
          <div v-for="f in b.floors" :key="`${b.block}-${f.floor}`" class="flex items-center gap-2">
            <span class="w-10 shrink-0 text-right text-xs opacity-60">
              {{ f.floor ?? '—' }}
            </span>
            <div class="flex flex-wrap gap-1">
              <button
                v-for="u in f.units"
                :key="u.id"
                type="button"
                class="relative min-h-[44px] min-w-[44px] rounded-token border px-2 text-xs font-medium"
                :class="[
                  cellClass[u.sale_status] ?? 'border-border',
                  selected?.id === u.id ? 'ring-2 ring-primary' : '',
                ]"
                :title="`${u.reference} · ${u.sale_status}`"
                @click="pick(u)"
              >
                {{ u.reference }}
                <span
                  v-if="showHeat && heatFor(u.id) > 0"
                  class="absolute -right-1 -top-1 rounded-full bg-primary px-1 text-[10px] font-semibold text-on-primary"
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
    <div v-if="selected" class="mt-4 rounded-token border border-border p-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p class="font-medium">{{ selected.reference }}</p>
          <p class="text-xs opacity-70">{{ selected.sale_status }} · {{ selected.price }}</p>
          <p v-if="countdown" class="text-xs font-medium text-warning">
            Hold expires in {{ countdown }}
          </p>
        </div>
        <slot name="actions" :unit="selected" />
      </div>
    </div>
  </BaseCard>
</template>
