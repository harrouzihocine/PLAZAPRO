<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { boxesApi } from '@/features/inventory/api'
import { formatMoney } from '@/features/payments/money'

// The box decision for ONE apartment entering (or on) a deal. Offered boxes:
//  - the boxes already LINKED to this apartment (they belong with it);
//  - the location's available UNLINKED boxes (picking one links it to the
//    apartment, server-side).
// Boxes linked to ANOTHER apartment are never shown — they are not this
// apartment's to take. `current` carries the boxes already carried on the
// deal for this apartment (they are not "available" anymore, but they must
// stay offered while editing).
const props = defineProps({
  unitId: { type: [String, Number], required: true },
  locationId: { type: [String, Number], default: null },
  modelValue: { type: Array, default: () => [] }, // selected box ids
  current: { type: Array, default: () => [] }, // [{ id, reference, price, ... }]
})
const emit = defineEmits(['update:modelValue'])

const linked = ref([])
const unlinked = ref([])
const loading = ref(false)

async function load() {
  if (!props.unitId) return
  loading.value = true
  try {
    const [own, pool] = await Promise.all([
      boxesApi.list({ unit_id: props.unitId, sale_status: 'available' }),
      props.locationId
        ? boxesApi.list({ location_id: props.locationId, sale_status: 'available', unlinked: 1 })
        : Promise.resolve([]),
    ])
    linked.value = own
    unlinked.value = pool
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => props.unitId, load)

// current (already on the deal) first, then the apartment's linked boxes,
// then the location's unlinked pool — deduped.
const candidates = computed(() => {
  const seen = new Set()
  const all = []
  for (const [list, isLinked] of [
    [props.current, true],
    [linked.value, true],
    [unlinked.value, false],
  ]) {
    for (const box of list) {
      if (seen.has(box.id)) continue
      seen.add(box.id)
      all.push({ ...box, linked: isLinked && box.unit_id != null })
    }
  }
  return all
})

function toggle(id) {
  const next = props.modelValue.includes(id)
    ? props.modelValue.filter((v) => v !== id)
    : [...props.modelValue, id]
  emit('update:modelValue', next)
}
</script>

<template>
  <div>
    <p v-if="loading" class="text-xs text-mute">Loading boxes…</p>
    <template v-else>
      <div v-if="candidates.length" class="flex flex-wrap gap-1.5">
        <button
          v-for="b in candidates"
          :key="b.id"
          type="button"
          class="rounded-full border px-2.5 py-1 text-xs transition-colors"
          :class="
            modelValue.includes(b.id)
              ? 'border-primary bg-highlight font-medium text-ink'
              : 'border-line text-mute hover:border-primary hover:text-ink'
          "
          @click="toggle(b.id)"
        >
          <i class="pi pi-car text-[10px]" aria-hidden="true" />
          {{ b.reference }}<template v-if="b.price"> · {{ formatMoney(b.price) }}</template>
          <span
            v-if="b.linked"
            class="ms-1 rounded bg-surface-100 px-1 text-[10px] uppercase tracking-wide dark:bg-surface-700"
            title="Already linked to this apartment"
          >
            linked
          </span>
        </button>
      </div>
      <p v-else class="text-xs text-mute">
        No box is linked to this apartment and none is free to link.
      </p>
    </template>
  </div>
</template>
