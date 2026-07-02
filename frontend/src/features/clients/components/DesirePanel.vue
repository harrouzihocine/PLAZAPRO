<script setup>
import { onMounted, reactive, watch } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import { useDynamicList } from '@/composables/useDynamicList'
import { useWilayas, useCommunes } from '@/composables/useGeography'
import { useClientsStore } from '@/features/clients/clientsStore'
import { useAuthStore } from '@/features/settings/store'

const props = defineProps({ clientId: { type: [String, Number], required: true } })
const store = useClientsStore()
const auth = useAuthStore()
const { wilayas } = useWilayas()
const { communes, load: loadCommunes } = useCommunes()
const { items: unitTypes } = useDynamicList('unit_types')

const selectClass =
  'w-full rounded-token border border-border bg-bg px-3 py-2 min-h-[44px] text-ink outline-none focus:border-primary'

const canEdit = () => auth.can('clients.create')
const canViewMatches = () => auth.can('units.view')
const canReserve = () => auth.can('units.reserve')

const form = reactive({
  wilaya_id: '',
  commune_id: '',
  type_id: '',
  budget_min: '',
  budget_max: '',
  floor_pref: '',
  notes: '',
})

// Cascade: reload communes when the wilaya changes; a user change clears the commune.
watch(
  () => form.wilaya_id,
  (id, prev) => {
    if (prev !== undefined && id !== prev) form.commune_id = ''
    loadCommunes(id)
  },
)

function fillFrom(desire) {
  Object.assign(form, {
    wilaya_id: desire?.wilaya_id ?? '',
    commune_id: desire?.commune_id ?? '',
    type_id: desire?.type_id ?? '',
    budget_min: desire?.budget_min ?? '',
    budget_max: desire?.budget_max ?? '',
    floor_pref: desire?.floor_pref ?? '',
    notes: desire?.notes ?? '',
  })
  loadCommunes(desire?.wilaya_id)
}

onMounted(async () => {
  const desire = await store.loadDesire(props.clientId)
  fillFrom(desire)
  if (canViewMatches()) await store.loadMatches(props.clientId)
})

async function save() {
  const payload = {
    wilaya_id: form.wilaya_id || null,
    commune_id: form.commune_id || null,
    type_id: form.type_id || null,
    budget_min: form.budget_min === '' ? null : Number(form.budget_min),
    budget_max: form.budget_max === '' ? null : Number(form.budget_max),
    floor_pref: form.floor_pref.trim() || null,
    notes: form.notes.trim() || null,
  }
  try {
    await store.saveDesire(props.clientId, payload)
    if (canViewMatches()) await store.loadMatches(props.clientId)
  } catch {
    /* error surfaced via store.error */
  }
}

function reserve(unit) {
  store.reserveMatch(props.clientId, unit.id)
}
</script>

<template>
  <BaseCard>
    <h2 class="mb-3 text-sm font-semibold uppercase opacity-60">Desire</h2>

    <!-- Editable form for users who can qualify leads (clients.create) -->
    <form v-if="canEdit()" class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
      <BaseSelect
        v-model="form.wilaya_id"
        label="Wilaya"
        placeholder="Any"
        :options="wilayas.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))"
      />
      <BaseSelect
        v-model="form.commune_id"
        label="Commune"
        placeholder="Any"
        :disabled="!form.wilaya_id"
        :options="communes.map((c) => ({ value: c.id, label: c.name }))"
      />
      <BaseSelect
        v-model="form.type_id"
        label="Type"
        placeholder="Any"
        :options="unitTypes.map((t) => ({ value: t.id, label: t.label }))"
      />
      <BaseInput v-model="form.floor_pref" label="Floor preference" />
      <BaseInput v-model="form.budget_min" label="Budget min" type="number" />
      <BaseInput v-model="form.budget_max" label="Budget max" type="number" />
      <label class="block sm:col-span-2">
        <span class="mb-1 block text-sm">Notes</span>
        <textarea v-model="form.notes" rows="2" :class="selectClass"></textarea>
      </label>
      <div class="sm:col-span-2">
        <BaseButton type="submit" :disabled="store.saving">Save desire</BaseButton>
      </div>
    </form>

    <!-- Read-only summary otherwise -->
    <dl v-else class="grid gap-2 text-sm sm:grid-cols-2">
      <div class="flex justify-between gap-2"><dt class="opacity-60">Wilaya</dt><dd>{{ store.desire?.wilaya?.name ?? 'Any' }}</dd></div>
      <div class="flex justify-between gap-2"><dt class="opacity-60">Commune</dt><dd>{{ store.desire?.commune?.name ?? 'Any' }}</dd></div>
      <div class="flex justify-between gap-2"><dt class="opacity-60">Type</dt><dd>{{ store.desire?.type?.label ?? 'Any' }}</dd></div>
      <div class="flex justify-between gap-2"><dt class="opacity-60">Budget</dt><dd>{{ store.desire?.budget_min ?? '—' }} – {{ store.desire?.budget_max ?? '—' }}</dd></div>
    </dl>

    <!-- Matching units -->
    <div v-if="canViewMatches()" class="mt-4 border-t border-border pt-3">
      <h3 class="mb-2 text-sm font-semibold">Matching units ({{ store.matches.length }})</h3>
      <div class="space-y-2">
        <div
          v-for="u in store.matches"
          :key="u.id"
          class="flex flex-col gap-2 rounded-token border border-border p-2 sm:flex-row sm:items-center"
        >
          <div class="flex-1 text-sm">
            <span class="font-medium">{{ u.reference }}</span>
            <span class="opacity-70"> · {{ u.type || '—' }} · {{ u.price }}</span>
          </div>
          <BaseButton v-if="canReserve()" variant="ghost" @click="reserve(u)">Reserve</BaseButton>
        </div>
        <p v-if="!store.matches.length" class="py-2 text-sm opacity-60">
          No available units match this desire yet.
        </p>
      </div>
    </div>
  </BaseCard>
</template>
