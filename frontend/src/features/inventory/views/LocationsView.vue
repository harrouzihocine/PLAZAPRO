<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import { useWilayas, useCommunes } from '@/composables/useGeography'
import { GTM_PRIORITIES } from '@/features/inventory/api'
import GtmPriorityBadge from '@/features/inventory/components/GtmPriorityBadge.vue'
import LocationMap from '@/features/inventory/components/LocationMap.vue'
import { googleMapsUrl } from '@/features/inventory/googleMaps'
import { useLocationsStore } from '@/features/inventory/locationsStore'
import { useAuthStore } from '@/features/settings/store'
import { confirmAction } from '@/composables/useConfirm'

const store = useLocationsStore()
const auth = useAuthStore()
const { wilayas } = useWilayas()
// Independent dependent-commune lists for the filter bar and the form.
const { communes: filterCommunes, load: loadFilterCommunes } = useCommunes()
const { communes: formCommunes, load: loadFormCommunes } = useCommunes()

const canManage = auth.can('locations.manage')

const blank = { name: '', code: '', wilaya_id: '', commune_id: '', address: '', description: '', expected_delivery_date: '', gtm_priority: 'medium', latitude: null, longitude: null }
const form = reactive({ ...blank })
const editingId = ref(null)
const showForm = ref(false)
const showMap = ref(false)
const mapsUrl = computed(() => googleMapsUrl(form))
// Archived projects are lazy-loaded the first time the section is opened.
const showArchived = ref(false)

onMounted(() => store.fetch())

// Cascade: reload the dependent commune list when the chosen wilaya changes.
// A user-driven change also clears the previously-picked commune.
watch(
  () => form.wilaya_id,
  (id, prev) => {
    if (prev !== undefined && id !== prev) form.commune_id = ''
    loadFormCommunes(id)
  },
)
watch(
  () => store.filters.wilaya_id,
  (id, prev) => {
    if (prev !== undefined && id !== prev) store.filters.commune_id = ''
    loadFilterCommunes(id)
    store.fetch()
  },
)

function openCreate() {
  Object.assign(form, blank)
  editingId.value = null
  showMap.value = false
  showForm.value = true
}

function openEdit(loc) {
  Object.assign(form, {
    name: loc.name,
    code: loc.code,
    wilaya_id: loc.wilaya_id ?? '',
    commune_id: loc.commune_id ?? '',
    address: loc.address ?? '',
    description: loc.description ?? '',
    expected_delivery_date: loc.expected_delivery_date ?? '',
    gtm_priority: loc.gtm_priority ?? 'medium',
    latitude: loc.latitude ?? null,
    longitude: loc.longitude ?? null,
  })
  loadFormCommunes(loc.wilaya_id)
  editingId.value = loc.id
  showMap.value = loc.latitude != null && loc.longitude != null
  showForm.value = true
}

async function submit() {
  const payload = {
    name: form.name.trim(),
    code: form.code.trim(),
    wilaya_id: form.wilaya_id || null,
    commune_id: form.commune_id || null,
    address: form.address.trim() || null,
    description: form.description.trim() || null,
    expected_delivery_date: form.expected_delivery_date || null,
    gtm_priority: form.gtm_priority,
    latitude: form.latitude ?? null,
    longitude: form.longitude ?? null,
  }
  try {
    if (editingId.value) {
      await store.update(editingId.value, payload)
    } else {
      await store.create(payload)
    }
    showForm.value = false
  } catch {
    /* error surfaced via store.error */
  }
}

// Archive: reversible. Hides the project and everything inside it until reactivated.
async function archive(loc) {
  if (
    await confirmAction({
      title: `Archive project "${loc.name}"?`,
      text: 'This archives the project and all its units and boxes. You can reactivate it later.',
      confirmText: 'Archive',
    })
  ) {
    store.archive(loc.id)
  }
}

function reactivate(loc) {
  store.reactivate(loc.id)
}

// Remove: terminal. Cancels the project and its inventory (kept + audited, not deleted).
async function remove(loc) {
  if (
    await confirmAction({
      title: `Remove project "${loc.name}"?`,
      text: 'This removes the project and everything inside it. The records are kept but marked cancelled.',
      confirmText: 'Remove',
      danger: true,
    })
  ) {
    store.cancel(loc.id)
  }
}

function toggleArchived() {
  showArchived.value = !showArchived.value
  if (showArchived.value) store.loadArchived()
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h1 class="text-xl font-semibold">Projects</h1>
        <p class="opacity-70">Real-estate projects, buildings and sites.</p>
      </div>
      <BaseButton v-if="canManage" @click="openCreate">New project</BaseButton>
    </div>

    <div class="flex flex-wrap gap-2">
      <BaseInput
        v-model="store.filters.q"
        label="Search"
        class="flex-1 min-w-[12rem]"
        @keyup.enter="store.fetch()"
      />
      <BaseSelect
        v-model="store.filters.wilaya_id"
        label="Wilaya"
        placeholder="All wilayas"
        :options="wilayas.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))"
      />
      <BaseSelect
        v-model="store.filters.commune_id"
        label="Commune"
        placeholder="All communes"
        :disabled="!store.filters.wilaya_id"
        :options="filterCommunes.map((c) => ({ value: c.id, label: c.name }))"
        @change="store.fetch()"
      />
      <BaseSelect
        v-model="store.filters.priority"
        label="GTM priority"
        placeholder="All priorities"
        :options="GTM_PRIORITIES"
        @change="store.fetch()"
      />
    </div>


    <BaseCard v-if="showForm && canManage">
      <form class="space-y-3" @submit.prevent="submit">
        <h2 class="font-semibold">{{ editingId ? 'Edit project' : 'New project' }}</h2>
        <div class="grid gap-3 sm:grid-cols-2">
          <BaseInput v-model="form.name" label="Name" />
          <BaseInput v-model="form.code" label="Code" />
          <BaseSelect
            v-model="form.wilaya_id"
            label="Wilaya"
            placeholder="— none —"
            :options="wilayas.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))"
          />
          <BaseSelect
            v-model="form.commune_id"
            label="Commune"
            placeholder="— none —"
            :disabled="!form.wilaya_id"
            :options="formCommunes.map((c) => ({ value: c.id, label: c.name }))"
          />
          <div>
            <BaseInput v-model="form.address" label="Address" />
            <a
              v-if="mapsUrl"
              :href="mapsUrl"
              target="_blank"
              rel="noopener noreferrer"
              title="Open in Google Maps"
              aria-label="Open in Google Maps"
              class="mt-1 inline-flex text-primary hover:opacity-80"
            >
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="18"
                height="18"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
              >
                <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z" />
                <circle cx="12" cy="10" r="3" />
              </svg>
            </a>
          </div>
          <BaseInput
            v-model="form.expected_delivery_date"
            label="Expected delivery date"
            type="date"
          />
          <BaseSelect
            v-model="form.gtm_priority"
            label="GTM priority"
            :clearable="false"
            :options="GTM_PRIORITIES"
          />
        </div>
        <div class="space-y-2">
          <button
            type="button"
            class="text-sm text-primary hover:underline"
            @click="showMap = !showMap"
          >
            {{ showMap ? 'Hide map' : '🗺 Pick location on map' }}
          </button>
          <LocationMap
            v-if="showMap"
            v-model:latitude="form.latitude"
            v-model:longitude="form.longitude"
            v-model:address="form.address"
            editable
          />
        </div>
        <BaseInput v-model="form.description" label="Description" />
        <div class="flex gap-2">
          <BaseButton type="submit" :disabled="store.saving">Save</BaseButton>
          <BaseButton type="button" variant="ghost" @click="showForm = false">Cancel</BaseButton>
        </div>
      </form>
    </BaseCard>

    <BaseCard>
      <p v-if="store.loading" class="py-4 text-center text-sm opacity-60">Loading…</p>
      <div v-else class="space-y-2">
        <div
          v-for="loc in store.items"
          :key="loc.id"
          class="flex flex-col gap-2 rounded-token border border-border p-3 sm:flex-row sm:items-center"
        >
          <div class="flex-1">
            <RouterLink
              :to="{ name: 'inventory.location', params: { id: loc.id } }"
              class="font-medium hover:text-primary"
            >
              {{ loc.name }}
            </RouterLink>
            <GtmPriorityBadge v-if="loc.gtm_priority" :priority="loc.gtm_priority" class="ml-2" />
            <span class="ml-2 text-xs opacity-60">
              {{ loc.code
              }}<template v-if="loc.wilaya"> · {{ loc.wilaya.name }}</template
              ><template v-if="loc.commune"> ({{ loc.commune.name }})</template>
            </span>
            <span v-if="loc.expected_delivery_date" class="ml-2 text-xs opacity-60">
              🏁 Delivery {{ loc.expected_delivery_date }}
            </span>
          </div>
          <div v-if="canManage" class="flex items-center gap-1">
            <BaseButton variant="ghost" @click="openEdit(loc)">Edit</BaseButton>
            <BaseButton variant="ghost" @click="archive(loc)">Archive</BaseButton>
            <BaseButton variant="ghost" @click="remove(loc)">Remove</BaseButton>
          </div>
        </div>
        <p v-if="!store.items.length" class="py-4 text-center text-sm opacity-60">
          No projects yet.
        </p>
      </div>
    </BaseCard>

    <!-- Archived projects: hidden by default, reactivatable one by one. -->
    <BaseCard v-if="canManage">
      <button
        type="button"
        class="text-sm font-semibold uppercase opacity-60 hover:opacity-100"
        @click="toggleArchived"
      >
        {{ showArchived ? 'Hide' : 'Show' }} archived projects
      </button>

      <div v-if="showArchived" class="mt-3 space-y-2">
        <div
          v-for="loc in store.archivedItems"
          :key="loc.id"
          class="flex flex-col gap-2 rounded-token border border-dashed border-border p-3 opacity-80 sm:flex-row sm:items-center"
        >
          <div class="flex-1">
            <span class="font-medium">{{ loc.name }}</span>
            <span class="ml-2 text-xs opacity-60">
              {{ loc.code
              }}<template v-if="loc.wilaya"> · {{ loc.wilaya.name }}</template
              ><template v-if="loc.commune"> ({{ loc.commune.name }})</template>
            </span>
            <span class="ml-2 text-xs uppercase opacity-50">archived</span>
          </div>
          <BaseButton variant="ghost" @click="reactivate(loc)">Reactivate</BaseButton>
        </div>
        <p v-if="!store.archivedItems.length" class="py-2 text-center text-sm opacity-60">
          No archived projects.
        </p>
      </div>
    </BaseCard>
  </div>
</template>
